<?php
declare(strict_types=1);

/**
 * Explicit administrator-triggered imports only. Never issue server-side HTTP
 * requests to arbitrary img src addresses without validating and pinning DNS.
 */
function media_remote_public_ipv4(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    $value = ip2long($ip);
    if ($value === false) return false;
    $value &= 0xffffffff;
    // Also block CGNAT, benchmarking, documentation and other non-routable ranges
    // that are not consistently rejected by PHP's NO_RES_RANGE flag.
    foreach ([[0x64400000, 10], [0xc0000000, 24], [0xc6120000, 15],
              [0xc0000200, 24], [0xc6336400, 24], [0xcb007100, 24]] as [$network, $bits]) {
        $mask = (0xffffffff << (32 - $bits)) & 0xffffffff;
        if (($value & $mask) === ($network & $mask)) return false;
    }
    return true;
}

/** Validate the URL and resolve its host before making an HTTP request. */
function media_remote_target(string $url): array
{
    if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
        throw new RuntimeException('图片网址无效或过长');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        throw new RuntimeException('图片网址格式不正确');
    }
    $scheme = strtolower($parts['scheme']);
    // A trailing dot changes cURL's DNS cache lookup key and could bypass
    // CURLOPT_RESOLVE pinning. Reject instead of normalizing just this field.
    if (str_ends_with($parts['host'], '.')) throw new RuntimeException('图片域名格式不正确');
    $host = strtolower($parts['host']);
    if (!in_array($scheme, ['https', 'http'], true) ||
        !preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $host) ||
        !str_contains($host, '.') || str_contains($host, '..') ||
        str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('仅允许来自公网域名的 HTTP(S) 图片');
    }
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port !== ($scheme === 'https' ? 443 : 80)) {
        throw new RuntimeException('不允许使用非标准端口下载图片');
    }
    $addresses = gethostbynamel($host);
    if (!is_array($addresses) || !$addresses) throw new RuntimeException('图片域名 DNS 解析失败');
    foreach ($addresses as $ip) {
        if (!media_remote_public_ipv4($ip)) throw new RuntimeException('图片域名指向非公网地址，已阻止请求');
    }
    return [$host, $port, $addresses[0]];
}

/** Download at most 6 MiB; no redirects, proxies, private network or invalid TLS. */
function media_remote_bytes(string $url): string
{
    if (!extension_loaded('curl')) throw new RuntimeException('PHP 未启用 curl 扩展，无法转存图片');
    [$host, $port, $ip] = media_remote_target($url);
    $body = '';
    $overflow = false;
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
        CURLOPT_PROXY => '', // Prevent environment proxy from bypassing pinned DNS.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'HowHub-Image-Importer/1.0',
        CURLOPT_HTTPHEADER => ['Accept: image/png,image/jpeg,image/webp,image/gif'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow): int {
            if (strlen($body) + strlen($chunk) > 6 * 1024 * 1024) {
                $overflow = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($host === 'cdn.nlark.com') $options[CURLOPT_REFERER] = 'https://www.yuque.com/';
    curl_setopt_array($curl, $options);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($overflow) throw new RuntimeException('图片超过 6MB 限制');
    if ($status !== 200) throw new RuntimeException('图片服务器返回 HTTP ' . $status);
    if (!$ok || $body === '') throw new RuntimeException('图片下载失败或连接超时');
    return $body;
}

/** Save an actual verified image to the normal HowHub media store. */
function media_import_remote_image(PDO $pdo, string $url, ?callable $downloader = null): string
{
    $bytes = $downloader === null ? media_remote_bytes($url) : $downloader($url);
    return media_store_import_bytes($pdo, $bytes);
}

/** Decode embedded raster data safely; SVG and Blob URLs are intentionally rejected. */
function media_import_data_image(PDO $pdo, string $uri): string
{
    if (strlen($uri) > 300000 || !preg_match('~^data:image/(png|jpe?g|webp|gif);base64,([a-zA-Z0-9+/=]+)$~D', $uri, $match)) {
        throw new RuntimeException('嵌入图片格式不支持或超过输入限制');
    }
    $bytes = base64_decode($match[2], true);
    if ($bytes === false) throw new RuntimeException('嵌入图片不是有效的 Base64');
    return media_store_import_bytes($pdo, $bytes);
}

/** Shared validation and registration for both external and embedded images. */
function media_store_import_bytes(PDO $pdo, mixed $bytes): string
{
    if (!is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > 6 * 1024 * 1024) {
        throw new RuntimeException('下载的图片大小无效（最大 6MB）');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    $ext = match ($mime) {
        'image/png' => 'png', 'image/jpeg' => 'jpg',
        'image/webp' => 'webp', 'image/gif' => 'gif',
        default => '',
    };
    $dimensions = @getimagesizefromstring($bytes);
    if ($ext === '' || !is_array($dimensions) || ($dimensions['mime'] ?? '') !== $mime ||
        $dimensions[0] < 1 || $dimensions[1] < 1 ||
        $dimensions[0] > 16000 || $dimensions[1] > 16000 ||
        $dimensions[0] * $dimensions[1] > 50000000) {
        throw new RuntimeException('下载结果不是允许的 PNG/JPEG/WebP/GIF 图片');
    }
    $subdir = date('Y/m');
    $root = media_directory('image');
    $dir = $root . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('本站图片存储目录不可写');
    }
    $relative = $subdir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $root . '/' . $relative;
    if (file_put_contents($dest, $bytes, LOCK_EX) !== strlen($bytes)) {
        @unlink($dest);
        throw new RuntimeException('本站图片保存失败，请检查目录权限与磁盘空间');
    }
    @chmod($dest, 0644);
    try {
        $name = '网页导入图片-' . date('Ymd-His') . '.' . $ext;
        $asset = media_register($pdo, 'image', $relative, $name, $mime, strlen($bytes));
        if (function_exists('url_import_record_asset')) url_import_record_asset($asset);
        return $asset['url'];
    } catch (Throwable $e) {
        @unlink($dest);
        throw $e;
    }
}

/**
 * Localize the images embedded in already sanitized and source-verified HTML.
 * On a failure preserve the external URL, but expose a concrete partial result.
 * No guide is saved or published by this routine.
 */
function media_localize_html(PDO $pdo, string $html, ?callable $onStage = null, ?callable $downloader = null): array
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="hh-images">' .
            $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$loaded || !($root = $doc->getElementById('hh-images'))) {
        throw new RuntimeException('生成 HTML 的图片解析失败');
    }
    $remote = [];
    foreach ($root->getElementsByTagName('img') as $img) {
        $src = $img->getAttribute('src');
        if (preg_match('~^https?://~i', $src)) $remote[] = $img;
    }
    if (count($remote) > 60) throw new RuntimeException('单次导入最多转存 60 张外部图片');
    $known = [];
    $localized = 0;
    $failed = 0;
    $errors = [];
    $started = microtime(true);
    foreach ($remote as $i => $image) {
        if ($onStage) $onStage('正在转存原图 ' . ($i + 1) . '/' . count($remote) . '…');
        $url = $image->getAttribute('src');
        try {
            if (microtime(true) - $started > 120) throw new RuntimeException('批量转存已超过 120 秒');
            if (!isset($known[$url])) $known[$url] = media_import_remote_image($pdo, $url, $downloader);
            $image->setAttribute('src', $known[$url]);
            $localized++;
        } catch (Throwable $e) {
            $failed++;
            $errors[] = '第 ' . ($i + 1) . ' 张：' .
                ($e instanceof RuntimeException ? $e->getMessage() : '转存处理失败');
            diag_event('warning', 'media', 'remote_import', 'failure',
                ['image_index' => $i + 1, 'error_type' => get_class($e)]);
        }
    }
    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    $out = sanitize_content($out);
    diag_event($failed ? 'warning' : 'info', 'media', 'remote_import',
        $failed ? 'partial' : 'success',
        ['total' => count($remote), 'localized' => $localized, 'failed' => $failed]);
    return [
        'html' => $out,
        'localized_count' => $localized,
        'failed_count' => $failed,
        'transfer_errors' => array_slice($errors, 0, 10),
    ];
}
