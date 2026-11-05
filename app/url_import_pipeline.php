<?php
declare(strict_types=1);

function url_import_plain_article(string $title, string $text, string $source, string $sourceLabel = '原文链接'): string
{
    $html = '<article><h2>' . h($title === '' ? '导入的内容' : $title) . '</h2>';
    $text = mb_substr($text, 0, 80000);
    foreach (preg_split('/(?:\r\n|\r|\n){1,}/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') $html .= '<p>' . h($line) . '</p>';
    }
    if ($source !== '') $html .= '<p><a href="' . h($source) . '">' . h($sourceLabel) . '</a></p>';
    return $html . '</article>';
}

function url_import_image_vision(string $data, string $mime): string
{
    if (!in_array($mime, ['image/png','image/jpeg','image/webp','image/gif'], true) ||
        strlen($data) > 6 * 1024 * 1024 || !@getimagesizefromstring($data)) {
        throw new RuntimeException('图片文件不是有效的 PNG/JPEG/WebP/GIF 或超过 6MB');
    }
    $content = [
        ['type'=>'text','text'=>'请只说明图片上可见的操作步骤、界面文字和可验证信息，不可猜测来源。无法辨认的内容必须明确说明。中文回答，限 1500 字。'],
        ['type'=>'image_url','image_url'=>['url'=>'data:' . $mime . ';base64,' . base64_encode($data),'detail'=>'low']],
    ];
    $result = ai_chat_content(['model'=>ai_config()['model'],'messages'=>[
        ['role'=>'system','content'=>'你是谨慎的教程图片内容分析助手，任何不可见的细节均不得臆测。'],
        ['role'=>'user','content'=>$content],
    ]]);
    if (mb_strlen(trim($result)) < 30) throw new RuntimeException('当前模型未返回足够的图片内容分析');
    return mb_substr($result, 0, 8000);
}

/** Pipeline for one public URL. No crawl of linked pages and no recursive downloads. */
function url_import_prepare(PDO $pdo, string $url, ?callable $onStage = null, ?callable $fixture = null): array
{
    $url = import_page_url($url);
    if ($onStage) $onStage('正在安全获取链接内容并检测文件类型…');
    $resource = null;
    try {
        $resource = url_import_fetch($url, 30 * 1024 * 1024, $fixture);
    } catch (Throwable $error) {
        // Some video hosts deny ordinary webpage fetching but expose public metadata.
        if (!url_import_video_host($url)) throw $error;
    }
    $finalUrl = $resource['url'] ?? $url;
    $mime = $resource === null ? 'text/html' : url_import_mime($resource);
    $data = (string)($resource['body'] ?? '');
    $title = '';
    $sourceKind = 'webpage';
    $warnings = [];
    $transcript = '';
    $html = '';
    if ($mime === 'text/html' || $mime === 'application/xhtml+xml' || ($resource === null && url_import_video_host($url))) {
        if ($data !== '' && strlen($data) > 2097152) throw new RuntimeException('网页 HTML 超过 2MB，请改为复制正文 HTML 导入');
        $meta = $data === '' ? ['title'=>'','description'=>'','thumbnail'=>'','video'=>true,'transcript'=>'','caption_urls'=>[]]
            : url_import_html_metadata($data, $finalUrl);
        $title = $meta['title'];
        $transcript = $meta['transcript'];
        if ($meta['video']) {
            $sourceKind = 'video_page';
            if ($onStage) $onStage('检测到视频页面，正在获取字幕或可靠的视频内容说明…');
            foreach ($meta['caption_urls'] as $captionUrl) {
                try {
                    $fetched = url_import_fetch($captionUrl, 1024 * 1024, $fixture);
                    $transcript = url_import_caption_text($fetched['body']);
                    if (mb_strlen($transcript) >= 60) break;
                } catch (Throwable $e) {
                    $warnings[] = '部分视频字幕链接不可访问';
                }
            }
            if (url_import_video_host($finalUrl) && $fixture === null) {
                $platform = url_import_platform_captions($finalUrl);
                if ($transcript === '') $transcript = $platform['text'];
                if ($title === '') $title = $platform['title'];
                if ($meta['description'] === '') $meta['description'] = $platform['description'];
                if ($meta['thumbnail'] === '') $meta['thumbnail'] = import_resolve_url($platform['thumbnail'], $finalUrl, true);
                if ($platform['warning'] !== '') $warnings[] = $platform['warning'];
            }
            $evidence = trim($transcript . "\n" . $meta['description']);
            if (mb_strlen($transcript) < 60 && mb_strlen($meta['description']) < 160) {
                throw new RuntimeException('视频没有足够的公开字幕或可核实的操作说明，无法可靠生成攻略；请提供带字幕的视频，或在网页中提供文字步骤');
            }
            $html = url_import_plain_article($title ?: '视频教程', $evidence, $finalUrl, '观看原视频');
            if ($meta['thumbnail'] !== '') {
                $html = str_replace('</article>', '<p><img src="' . h($meta['thumbnail']) . '" alt="视频预览图"></p></article>', $html);
            }
            if ($transcript === '') $warnings[] = '未获取视频字幕，仅依据发布者文字说明生成，请检查原视频';
        } else {
            $sourceKind = 'webpage';
            $html = url_import_article_html($data);
            $html .= '<p><a href="' . h($finalUrl) . '">文章来源</a></p>';
        }
    } elseif (str_starts_with($mime, 'video/')) {
        $sourceKind = 'video_file';
        if ($onStage) $onStage('正在抽取视频关键画面，交给 AI 识别可见操作…');
        $suffix = $mime === 'video/webm' ? '.webm' : '.mp4';
        $frames = url_import_frames($data, $suffix);
        $notes = url_import_vision_notes($frames);
        $media = [];
        foreach ($frames as $jpeg) $media[] = media_store_import_bytes($pdo, $jpeg);
        $saved = url_import_archive($pdo, $data, $suffix === '.webm' ? 'webm' : 'mp4', '原始视频');
        $html = url_import_plain_article('视频画面分析', $notes, $saved, '下载转存的视频文件');
        foreach ($media as $i=>$src) $html = str_replace('</article>', '<p><img src="' . h($src) . '" alt="关键画面 ' . ($i+1) . '"></p></article>', $html);
        $warnings[] = '该视频只分析抽取画面，不包含音轨语音转写，不能补充画面之外的教学信息';
    } elseif (in_array($mime, ['image/png','image/jpeg','image/webp','image/gif'], true)) {
        $sourceKind = 'image';
        if ($onStage) $onStage('正在让多模态 AI 识别图片中的操作说明…');
        $notes = url_import_image_vision($data, $mime);
        $local = media_store_import_bytes($pdo, $data);
        $html = url_import_plain_article('图片内容分析', $notes, $finalUrl);
        $html = str_replace('</article>', '<p><img src="' . h($local) . '" alt="来源图片"></p></article>', $html);
    } elseif (in_array($mime, ['application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
        $extension = match ($mime) {
            'application/pdf'=>'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
            default=>'xlsx',
        };
        $sourceKind = $extension;
        if ($onStage) $onStage('正在抽取文档中的文字并安全转存原文件…');
        $body = url_import_document_text($data, $extension);
        $local = url_import_archive($pdo, $data, $extension, '来源文档');
        $html = url_import_plain_article('文档操作说明', $body, $local, '下载原始文档');
    } elseif (in_array($mime, ['text/plain','text/markdown','text/x-markdown','application/json','text/xml','application/xml'], true)) {
        $sourceKind = 'text';
        $body = $data;
        if ($mime === 'application/json') {
            $parsed = json_decode($data, true);
            if (is_array($parsed)) $body = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        } elseif (str_contains($mime, 'xml')) {
            $body = html_entity_decode(strip_tags($data), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $html = url_import_plain_article('链接中的文字内容', $body, $finalUrl);
    } else {
        throw new RuntimeException('暂不支持该链接的文件类型：' . mb_substr($mime ?: 'unknown', 0, 100) . '；可先转换为 HTML、PDF、DOCX、文本或 MP4');
    }
    if (strlen($html) > 300000) throw new RuntimeException('抽取内容超过导入上限，请分段导入');
    $source = import_prepare_source($html, $sourceKind === 'webpage' ? $finalUrl : '');
    $source = import_prepare_embedded($pdo, $source);
    $source['source_kind'] = $sourceKind;
    $source['source_title'] = $title;
    $source['source_url'] = $finalUrl;
    $source['warnings'] = array_values(array_unique(array_merge($source['warnings'] ?? [], $warnings)));
    diag_event('info','url_import','extract','success',[
        'kind'=>$sourceKind, 'images'=>count($source['images']), 'links'=>count($source['links']),
        'text_chars'=>mb_strlen($source['content']),
    ]);
    return $source;
}
