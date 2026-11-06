<?php
declare(strict_types=1);

/**
 * Optional audio transcription, only for a licensed directly downloaded video.
 * Disabled by default. Sends at most the first 90 seconds of mono audio to
 * the operator-configured OpenAI-compatible transcription endpoint.
 */
function agent_audio_parse_transcript(string $response): string
{
    $data=json_decode($response,true);
    if (!is_array($data) || !is_string($data['text']??null)) throw new RuntimeException('音频转写服务返回格式无效');
    $text=trim($data['text']);
    if (mb_strlen($text)<10 || mb_strlen($text)>15000) throw new RuntimeException('音频转写结果太短或超出长度上限');
    return $text;
}

function agent_audio_transcribe_video(string $videoBytes,string $suffix): array
{
    if (setting('agent_stt_enabled','0')!=='1') return ['text'=>'','warning'=>'未启用音轨转写；仅识别视频画面'];
    if (strlen($videoBytes)>10*1024*1024 || strlen($videoBytes)<100) {
        return ['text'=>'','warning'=>'视频不符合安全转写大小限制'];
    }
    $binary=url_import_bin('ffmpeg');
    if ($binary==='') return ['text'=>'','warning'=>'音轨转写需要服务器安装 FFmpeg'];
    $config=ai_config();
    if (!$config['configured']) return ['text'=>'','warning'=>'尚未配置 AI 转写服务'];
    $model=trim(setting('agent_stt_model','whisper-1'));
    if (!preg_match('/^[a-zA-Z0-9._:+-]{1,100}$/D',$model)) return ['text'=>'','warning'=>'音频模型 ID 不合法'];
    $dir=sys_get_temp_dir().'/hh-agent-stt-'.bin2hex(random_bytes(12));
    if (!mkdir($dir,0700)) throw new RuntimeException('无法建立音频临时目录');
    $video=$dir.'/input'.(in_array($suffix,['.mp4','.mov','.webm'],true)?$suffix:'.mp4');
    $audio=$dir.'/sample.wav';
    try {
        if (file_put_contents($video,$videoBytes)!==strlen($videoBytes)) throw new RuntimeException('视频音轨临时写入失败');
        url_import_process([$binary,'-nostdin','-hide_banner','-loglevel','error',
            '-i',$video,'-t','90','-vn','-ac','1','-ar','16000','-c:a','pcm_s16le',
            '-y',$audio],35,16384);
        $size=@filesize($audio);
        if (!$size || $size>3.5*1024*1024) throw new RuntimeException('音频提取失败或超出 90 秒限制');
        if (!extension_loaded('curl')) throw new RuntimeException('需要 PHP curl 扩展');
        $key=ai_decrypt(setting('ai_api_key_cipher'));
        $base=rtrim((string)$config['base_url'],'/');
        if (!preg_match('~^https?://~i',$base)) throw new RuntimeException('AI 服务地址无效');
        $ch=curl_init($base.'/audio/transcriptions');
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>90,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROXY=>'',
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Accept: application/json'],
            CURLOPT_POSTFIELDS=>[
                'model'=>$model,
                'file'=>new CURLFile($audio,'audio/wav','excerpt.wav'),
                'response_format'=>'json',
            ],
        ]);
        $raw=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($raw) || strlen($raw)>100000 || $status!==200) {
            throw new RuntimeException('配置的模型未成功提供兼容音频转写能力，HTTP '.$status);
        }
        $text=agent_audio_parse_transcript($raw);
        return ['text'=>$text,'warning'=>'音轨仅转写视频开头最多 90 秒，后续音频尚未分析；需人工检查'];
    } catch (Throwable $e) {
        diag_event('warning','discovery_agent','audio_transcription','failure',['type'=>get_class($e)]);
        return ['text'=>'','warning'=>'音频转写不可用（'.mb_substr($e->getMessage(),0,100).'），继续只分析可见画面'];
    } finally {
        @unlink($video);
        @unlink($audio);
        @rmdir($dir);
    }
}
