<?php
declare(strict_types=1);

/**
 * Optional original illustration. Model must support /v1/images/generations
 * with base64 JSON output. Absolutely no third-party image URLs are fetched.
 * One image per article and one provider call per scheduler tick.
 */
function agent_topic_image_api(string $prompt, ?callable $fixture = null): string
{
    if (mb_strlen($prompt)<12 || mb_strlen($prompt)>600) throw new RuntimeException('原创示意图提示词长度无效');
    if ($fixture!==null) {
        $result=$fixture($prompt);
        if (!is_array($result)) throw new RuntimeException('图像生成响应不是 JSON');
    } else {
        $config=ai_config();
        if (!$config['configured']) throw new RuntimeException('还没有配置 AI 供应商');
        $model=setting('agent_original_image_model','gpt-image-1');
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:+-]{0,100}$/D',$model)) {
            throw new RuntimeException('原创图片模型 ID 格式不合法');
        }
        $payload=json_encode([
            'model'=>$model,
            'prompt'=>'Produce a fully original, clear educational conceptual illustration. No copyrighted characters, logos, real software screenshots, brand imitations, unverifiable measurements, realistic IDs or credentials. This must be an illustrative concept, not an exact source reproduction. Topic: '.$prompt,
            'size'=>'1024x1024',
            'n'=>1,
            'response_format'=>'b64_json',
        ],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!extension_loaded('curl')) throw new RuntimeException('图片接口需要 PHP curl 扩展');
        $key=ai_decrypt(setting('ai_api_key_cipher'));
        $ch=curl_init($config['base_url'].'/images/generations');
        $buffer='';$overflow=false;
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>120,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Accept: application/json','Content-Type: application/json'],
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_PROXY=>'',
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$buffer,&$overflow): int {
                if (strlen($buffer)+strlen($chunk)>12*1024*1024) {$overflow=true;return 0;}
                $buffer.=$chunk;
                return strlen($chunk);
            },
        ]);
        $ok=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($overflow) throw new RuntimeException('AI 图片响应超过 12MB 上限');
        if (!$ok || $status!==200) throw new RuntimeException('AI 模型未成功返回原创图片（HTTP '.$status.'），请检查图像模型权限');
        $result=json_decode($buffer,true);
        if (!is_array($result)) throw new RuntimeException('AI 图片生成接口返回格式无效');
    }
    $base64=$result['data'][0]['b64_json']??'';
    if (!is_string($base64) || strlen($base64)<50 || strlen($base64)>8*1024*1024) {
        throw new RuntimeException('图像生成接口没有返回可接受的 base64 图片');
    }
    $bytes=base64_decode($base64,true);
    if (!is_string($bytes)) throw new RuntimeException('图片 Base64 解码失败');
    return $bytes;
}

function agent_topic_process_illustration(PDO $pdo,array $idea,?callable $imageApi=null): array
{
    $id=(int)$idea['id'];
    if ($idea['status']!=='review'||$idea['asset_status']!=='pending') return ['status'=>'idle'];
    if (setting('agent_original_image_enabled','0')!=='1') {
        $pdo->prepare("UPDATE agent_topic_ideas SET asset_status='skipped' WHERE id=?")->execute([$id]);
        return ['status'=>'skipped'];
    }
    try {
        $bytes=agent_topic_image_api((string)$idea['image_prompt'],$imageApi);
        $url=media_store_import_bytes($pdo,$bytes);
        $figure='<figure><img src="'.h($url).'" alt="AI 原创教学概念示意图"><figcaption>AI 生成的概念示意图，非真实设备或软件截图</figcaption></figure>';
        $pdo->prepare("UPDATE agent_topic_ideas SET html=?,image_url=?,asset_status='complete' WHERE id=? AND status='review'")
            ->execute([sanitize_content((string)$idea['html'].$figure),$url,$id]);
        agent_event($pdo,'topic_image','success','已创建本站原创概念配图，不下载第三方图片');
        return ['status'=>'generated','url'=>$url];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE agent_topic_ideas SET asset_status='failed',review_notes=review_notes||? WHERE id=?")
            ->execute(["\n原创图片生成失败：".mb_substr($e->getMessage(),0,120),$id]);
        agent_event($pdo,'topic_image','error',get_class($e).' 图片生成失败');
        return ['status'=>'failed','message'=>$e->getMessage()];
    }
}
