<?php
declare(strict_types=1);

function url_import_bin(string $name): string
{
    if (!in_array($name, ['yt-dlp','ffmpeg','pdftotext','unzip'], true)) return '';
    foreach (['/usr/bin/', '/usr/local/bin/'] as $dir) {
        $path = $dir . $name;
        if (is_file($path) && is_executable($path)) return $path;
    }
    return '';
}

/** Run fixed executable argument arrays; no shell, bounded output and runtime. */
function url_import_process(array $args, int $seconds = 20, int $limit = 2097152): string
{
    if (!$args || !is_string($args[0]) || !is_executable($args[0])) throw new RuntimeException('所需文档或视频分析工具未安装');
    $proc = proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, ['PATH'=>'/usr/local/bin:/usr/bin','HOME'=>'/tmp']);
    if (!is_resource($proc)) throw new RuntimeException('无法启动文件分析工具');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $exit = null;
    try {
        do {
            $stdout .= (string)stream_get_contents($pipes[1], max(1, $limit - strlen($stdout) + 1));
            $stderr .= (string)stream_get_contents($pipes[2], max(1, 8192 - strlen($stderr) + 1));
            if (strlen($stdout) > $limit || strlen($stderr) > 8192) throw new RuntimeException('分析工具输出过大');
            $status = proc_get_status($proc);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            if (microtime(true) - $started > $seconds) throw new RuntimeException('文档或视频分析超时');
            usleep(100000);
        } while (true);
        $stdout .= (string)stream_get_contents($pipes[1]);
        if (strlen($stdout) > $limit) throw new RuntimeException('分析工具输出超过限制');
    } finally {
        if ($exit === null) proc_terminate($proc, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    if ($exit !== 0) throw new RuntimeException('文件解析失败，可能需要字幕、登录权限或对应解码支持');
    return $stdout;
}

function url_import_video_host(string $url): bool
{
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    foreach (['youtube.com','youtu.be','bilibili.com','douyin.com','tiktok.com','vimeo.com'] as $known) {
        if ($host === $known || str_ends_with($host, '.' . $known)) return true;
    }
    return false;
}

/** yt-dlp is optional; use subtitles only from known video hosting sites. */
function url_import_platform_captions(string $url): array
{
    if (!url_import_video_host($url)) return ['text'=>'','title'=>'','description'=>'','thumbnail'=>'','warning'=>''];
    $binary = url_import_bin('yt-dlp');
    if ($binary === '') return ['text'=>'','title'=>'','description'=>'','thumbnail'=>'',
        'warning'=>'服务器尚未安装 yt-dlp，无法从该视频平台尝试提取字幕'];
    try {
        $raw = url_import_process([$binary,'--ignore-config','--no-playlist','--skip-download',
            '--dump-single-json','--no-warnings','--socket-timeout','8','--retries','0',$url], 35, 3 * 1024 * 1024);
        $metadata = json_decode($raw, true);
        if (!is_array($metadata)) throw new RuntimeException('视频元数据不完整');
        $subtitleUrl = '';
        foreach (['subtitles','automatic_captions'] as $key) {
            $variants = $metadata[$key] ?? [];
            if (!is_array($variants)) continue;
            foreach (['zh-Hans','zh-CN','zh','zh-Hant','en','en-US'] as $language) {
                foreach ((array)($variants[$language] ?? []) as $subtitle) {
                    if (!is_array($subtitle)) continue;
                    if (!in_array(strtolower((string)($subtitle['ext'] ?? '')), ['vtt','srt'], true)) continue;
                    $candidate = (string)($subtitle['url'] ?? '');
                    if (str_starts_with($candidate, 'https://')) { $subtitleUrl = $candidate; break 3; }
                }
            }
        }
        $text = '';
        if ($subtitleUrl !== '') {
            $caption = url_import_fetch($subtitleUrl, 1024 * 1024);
            $text = url_import_caption_text($caption['body']);
        }
        return [
            'text'=>$text, 'title'=>mb_substr((string)($metadata['title'] ?? ''), 0, 180),
            'description'=>mb_substr((string)($metadata['description'] ?? ''), 0, 15000),
            'thumbnail'=>(string)($metadata['thumbnail'] ?? ''),
            'warning'=>$text === '' ? '视频平台没有公开可获取的字幕；不会将视频标题冒充完整教学过程' : '',
        ];
    } catch (Throwable $e) {
        diag_event('warning', 'url_import', 'caption_extract', 'failure', ['reason'=>get_class($e)]);
        return ['text'=>'','title'=>'','description'=>'','thumbnail'=>'','warning'=>'视频平台字幕暂无法获取；可能需要登录或网站限制访问'];
    }
}

/** Sample low-resolution frames of a direct video for a multimodal AI model. */
function url_import_frames(string $bytes, string $suffix = '.mp4'): array
{
    $binary = url_import_bin('ffmpeg');
    if ($binary === '') throw new RuntimeException('处理无字幕视频需要在服务器安装 ffmpeg');
    if (strlen($bytes) > 30 * 1024 * 1024) throw new RuntimeException('单个直链视频超过 30MB，无法转存分析');
    $base = sys_get_temp_dir() . '/howhub-' . bin2hex(random_bytes(12));
    if (!mkdir($base, 0700)) throw new RuntimeException('无法创建视频解析临时目录');
    $path = $base . '/input' . $suffix;
    $frames = [];
    try {
        if (file_put_contents($path, $bytes) !== strlen($bytes)) throw new RuntimeException('写入视频缓存失败');
        url_import_process([$binary,'-nostdin','-hide_banner','-loglevel','error',
            '-i',$path,'-vf','fps=1/15,scale=640:-2','-frames:v','4',
            '-q:v','5',$base . '/frame-%02d.jpg'], 30, 16384);
        foreach (glob($base . '/frame-*.jpg') ?: [] as $file) {
            $frame = file_get_contents($file);
            if (is_string($frame) && strlen($frame) <= 250000 && @getimagesizefromstring($frame)) {
                $frames[] = $frame;
            }
        }
    } finally {
        foreach (glob($base . '/*') ?: [] as $file) @unlink($file);
        @rmdir($base);
    }
    if (!$frames) throw new RuntimeException('无法从视频提取有效画面，请检查格式和解码器');
    return $frames;
}

function url_import_vision_notes(array $frames): string
{
    $message = [['type'=>'text','text'=>'请仅依据这些按时间排序的视频抽帧，描述实际可见的操作界面、按钮、步骤及其变化。没有证据的步骤不得猜测；无法读出画面就明确回答无法识别。不包含语音，不能推断讲解内容。请用中文回复最多 1200 字。']];
    foreach (array_slice($frames, 0, 4) as $jpeg) {
        $message[] = ['type'=>'image_url','image_url'=>[
            'url'=>'data:image/jpeg;base64,' . base64_encode($jpeg),'detail'=>'low']];
    }
    $response = ai_chat_content(['model'=>ai_config()['model'], 'messages'=>[
        ['role'=>'system','content'=>'你是谨慎的视频画面分析助手。不得编造看不到的动作。'],
        ['role'=>'user','content'=>$message],
    ]]);
    if (mb_strlen(trim($response)) < 30 || preg_match('/无法(查看|识别|分析|处理).*图片|不支持.*图像/u', $response)) {
        throw new RuntimeException('当前 AI 模型不能可靠理解抽帧画面，请换用支持图片理解的模型');
    }
    return mb_substr($response, 0, 9000);
}
