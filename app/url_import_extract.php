<?php
declare(strict_types=1);

function url_import_mime(array $response): string
{
    $type = $response['type'];
    if ($type !== '' && $type !== 'application/octet-stream') return $type;
    $data = $response['body'];
    $path = strtolower((string)(parse_url($response['url'], PHP_URL_PATH) ?: ''));
    if (str_starts_with($data, '%PDF-')) return 'application/pdf';
    if (str_starts_with($data, "\x00\x00\x00") && str_contains(substr($data, 0, 16), 'ftyp')) return 'video/mp4';
    foreach (['.md'=>'text/markdown','.txt'=>'text/plain','.html'=>'text/html','.htm'=>'text/html',
              '.mp4'=>'video/mp4','.webm'=>'video/webm','.json'=>'application/json','.pdf'=>'application/pdf',
              '.docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'] as $ext=>$mime) {
        if (str_ends_with($path, $ext)) return $mime;
    }
    if (preg_match('/<html|<!doctype html|<article/i', substr($data, 0, 512))) return 'text/html';
    return $type;
}

/** Extract safe metadata without executing page scripts or visiting embedded URLs. */
function url_import_html_metadata(string $html, string $url): array
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    } finally { libxml_clear_errors(); libxml_use_internal_errors($prev); }
    $title = trim($doc->getElementsByTagName('title')->item(0)?->textContent ?? '');
    $description = '';
    $thumb = '';
    $video = false;
    $transcript = '';
    $captionLinks = [];
    foreach ($doc->getElementsByTagName('meta') as $node) {
        $key = strtolower($node->getAttribute('name') ?: $node->getAttribute('property'));
        $value = trim($node->getAttribute('content'));
        if ($key === 'og:title' || $key === 'twitter:title') $title = $value;
        if ($key === 'description' || $key === 'og:description') $description = mb_substr($value, 0, 6000);
        if (in_array($key, ['og:image','twitter:image'], true)) $thumb = import_resolve_url($value, $url, true);
        if ($key === 'og:type' && str_contains(strtolower($value), 'video')) $video = true;
        if (in_array($key, ['og:video','og:video:url','twitter:player'], true)) $video = true;
    }
    foreach ($doc->getElementsByTagName('video') as $node) {
        $video = true;
        foreach ($node->getElementsByTagName('track') as $track) {
            $src = import_resolve_url($track->getAttribute('src'), $url);
            if ($src !== '' && count($captionLinks) < 5) $captionLinks[] = $src;
        }
    }
    foreach ($doc->getElementsByTagName('script') as $node) {
        if (strtolower(trim($node->getAttribute('type'))) !== 'application/ld+json') continue;
        $data = json_decode(trim($node->textContent), true);
        if (!is_array($data)) continue;
        $entities = [$data];
        if (isset($data['@graph']) && is_array($data['@graph'])) $entities = $data['@graph'];
        foreach ($entities as $entity) {
            if (!is_array($entity)) continue;
            $type = $entity['@type'] ?? '';
            if (is_array($type)) $type = implode(',', $type);
            if (!is_string($type) || !str_contains(strtolower($type), 'videoobject')) continue;
            $video = true;
            if (!$title) $title = (string)($entity['name'] ?? '');
            if (!$description) $description = (string)($entity['description'] ?? '');
            if (isset($entity['transcript']) && is_string($entity['transcript'])) {
                $transcript = mb_substr($entity['transcript'], 0, 90000);
            }
            if ($thumb === '') {
                $t = $entity['thumbnailUrl'] ?? '';
                if (is_array($t)) $t = $t[0] ?? '';
                if (is_string($t)) $thumb = import_resolve_url($t, $url, true);
            }
        }
    }
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if (preg_match('/(^|\.)youtube\.com$|(^|\.)youtu\.be$|(^|\.)bilibili\.com$|(^|\.)douyin\.com$|(^|\.)tiktok\.com$|(^|\.)vimeo\.com$/', $host)) $video = true;
    return [
        'title'=>mb_substr(trim($title), 0, 180),
        'description'=>mb_substr(trim($description), 0, 6000),
        'thumbnail'=>$thumb,
        'video'=>$video, 'transcript'=>$transcript,
        'caption_urls'=>array_values(array_unique($captionLinks)),
    ];
}

/** Convert captions to plain lines; never execute or trust subtitle markup. */
function url_import_caption_text(string $data): string
{
    $data = preg_replace('/<[^>]*>/', ' ', $data) ?? $data;
    $data = preg_replace('/\{[^}]{0,200}\}/', '', $data) ?? $data;
    $rows = [];
    $prev = '';
    foreach (preg_split('/\r\n|\r|\n/', $data) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'WEBVTT') ||
            str_contains($line, '-->') || preg_match('/^(?:\d+|NOTE|STYLE|REGION)$/', $line)) continue;
        if ($line === $prev) continue;
        $rows[] = $line;
        $prev = $line;
        if (count($rows) >= 7000) break;
    }
    return mb_substr(implode("\n", $rows), 0, 90000);
}

/**
 * For full webpages choose the recognized article container, retaining
 * inner HTML and media references, not the entire nav-heavy page.
 */
function url_import_article_html(string $html): string
{
    if (strlen($html) <= 300000) return $html;
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    } finally { libxml_clear_errors(); libxml_use_internal_errors($prev); }
    $article = import_select_body($doc);
    $source = $doc->saveHTML($article);
    if (!is_string($source) || strlen($source) > 300000) {
        throw new RuntimeException('来源网页正文超过 300KB，暂不支持直接导入；请改用 HTML 粘贴方式');
    }
    return $source;
}
