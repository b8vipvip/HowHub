<?php
declare(strict_types=1);

/** Prepare narration solely from our own newly generated article. */
function agent_topic_audio_script(array $idea): string
{
    $title=mb_substr(trim((string)$idea['title']),0,160);
    $doc=new DOMDocument('1.0','UTF-8');
    $old=libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.
            sanitize_content((string)$idea['html']).'</body></html>',
            LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    } finally {libxml_clear_errors();libxml_use_internal_errors($old);}
    $pieces=['本期原创教程：'.$title.'。'];
    $xpath=new DOMXPath($doc);
    foreach($xpath->query('//h3 | //li') ?: [] as $node) {
        $content=trim($node->textContent);
        if ($content==='') continue;
        $pieces[]=mb_substr($content,0,190).'。';
        if (count($pieces)>=11) break;
    }
    $text=mb_substr(implode("\n",$pieces),0,1150);
    if (mb_strlen($text)<60) throw new RuntimeException('教程步骤过短，不适合生成配音');
    return $text;
}

/** Explicit opt-in, returns binary MP3; no third-party source text is sent. */
function agent_topic_audio_speech(string $script, ?callable $fixture = null): string
{
    if (mb_strlen($script)<30 || mb_strlen($script)>1400) throw new RuntimeException('配音讲稿超出长度限制');
    if ($fixture!==null) {
        $raw=$fixture($script);
        if (!is_string($raw)) throw new RuntimeException('音频生成没有返回字节数据');
        return $raw;
    }
    $config=ai_config();
    if (!$config['configured']) throw new RuntimeException('未配置音频生成供应商');
    $model=trim(setting('agent_original_tts_model','tts-1'));
    $voice=trim(setting('agent_original_tts_voice','alloy'));
    if (!preg_match('/^[A-Za-z0-9._:+-]{1,100}$/D',$model) ||
        !preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$voice)) {
        throw new RuntimeException('语音模型或音色 ID 无效');
    }
    $json=json_encode(['model'=>$model,'voice'=>$voice,
        'input'=>$script,'response_format'=>'mp3'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $bytes='';
    $overflow=false;
    $ch=curl_init($config['base_url'].'/audio/speech');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$json,
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>100,
        CURLOPT_PROXY=>'',
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.ai_decrypt(setting('ai_api_key_cipher')),
            'Content-Type: application/json','Accept: audio/mpeg',
        ],
        CURLOPT_WRITEFUNCTION=>static function ($curl,string $chunk) use (&$bytes,&$overflow): int {
            if (strlen($bytes)+strlen($chunk)>5*1024*1024) {$overflow=true;return 0;}
            $bytes.=$chunk;return strlen($chunk);
        },
    ]);
    $ok=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($overflow || !$ok || $code!==200) {
        throw new RuntimeException('音频模型未成功返回 MP3（HTTP '.$code.'），请核对模型兼容性');
    }
    return $bytes;
}

function agent_topic_audio_process(PDO $pdo,array $idea,?callable $fixture=null): array
{
    $id=(int)$idea['id'];
    $q=$pdo->prepare('SELECT audio_status FROM agent_topic_av WHERE idea_id=?');
    $q->execute([$id]);
    if ($q->fetchColumn()!=='pending' || $idea['status']!=='review') return ['status'=>'idle'];
    if (setting('agent_original_audio_enabled','0')!=='1') {
        $pdo->prepare("UPDATE agent_topic_av SET audio_status='skipped' WHERE idea_id=?")->execute([$id]);
        return ['status'=>'skipped'];
    }
    try {
        $mp3=agent_topic_audio_speech(agent_topic_audio_script($idea),$fixture);
        $url=agent_topic_generated_file($pdo,$mp3,'audio');
        agent_topic_av_append($pdo,$id,'audio',$url);
        $pdo->prepare("UPDATE agent_topic_av SET audio_status='complete',audio_url=? WHERE idea_id=?")
            ->execute([$url,$id]);
        agent_event($pdo,'topic_audio','success','仅根据本站原创稿生成配音，不转录外站内容');
        return ['status'=>'complete','url'=>$url];
    } catch(Throwable $e) {
        $pdo->prepare("UPDATE agent_topic_av SET audio_status='failed',notes=? WHERE idea_id=?")
            ->execute([mb_substr($e->getMessage(),0,240),$id]);
        agent_event($pdo,'topic_audio','error',get_class($e).' 配音生成失败');
        return ['status'=>'failed','message'=>$e->getMessage()];
    }
}
