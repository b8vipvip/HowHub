<?php
declare(strict_types=1);

/** Resolve a resource URL against a pasted page URL, without fetching it. */
function import_resolve_url(string $value, string $base = '', bool $image = false): string
{
    $url = trim(str_replace(['https\\://','http\\://','\\/'], ['https://','http://','/'], html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return '';
    if ($image && preg_match('~^data:image/(?:png|jpeg|jpg|webp|gif);base64,[a-zA-Z0-9+/=]+$~D', $url)) {
        return strlen($url) <= 300000 ? $url : '';
    }
    if ($image && str_starts_with($url, '/uploads/') && !str_contains($url, '..')) return $url;
    if (preg_match('~^https?://~i', $url)) return $image ? safe_image($url) : safe_link($url);
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) || str_starts_with($url, '#') || $base === '') return '';
    $origin = parse_url($base);
    if (!is_array($origin) || !isset($origin['scheme'], $origin['host'])) return '';
    $scheme = strtolower($origin['scheme']);
    if (!in_array($scheme, ['http','https'], true)) return '';
    if (str_starts_with($url, '//')) $resolved = $scheme . ':' . $url;
    else {
        $basePath = (string)($origin['path'] ?? '/');
        $basePath = $basePath === '' ? '/' : $basePath;
        if ($url[0] === '?') { $path = $basePath; $query = $url; }
        else {
            $parts = explode('?', $url, 2);
            $path = str_starts_with($parts[0], '/') ? $parts[0] :
                substr($basePath, 0, strrpos($basePath, '/') + 1) . $parts[0];
            $query = isset($parts[1]) ? '?' . $parts[1] : '';
        }
        $segments = [];
        foreach (explode('/', $path) as $piece) {
            if ($piece === '' || $piece === '.') continue;
            if ($piece === '..') { array_pop($segments); continue; }
            $segments[] = $piece;
        }
        $port = isset($origin['port']) ? ':' . $origin['port'] : '';
        $resolved = $scheme . '://' . $origin['host'] . $port . '/' . implode('/', $segments) . $query;
    }
    return $image ? safe_image($resolved) : safe_link($resolved);
}

function import_page_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (strlen($url) > 2048 || !preg_match('~^https?://~i', $url) ||
        preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new InvalidArgumentException('原网页 URL 必须是有效的 HTTP(S) 网址');
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) ||
        filter_var($p['host'], FILTER_VALIDATE_IP) || !str_contains($p['host'], '.')) {
        throw new InvalidArgumentException('原网页 URL 不允许包含用户信息或 IP 地址');
    }
    if (isset($p['port']) && (int)$p['port'] !== (strtolower($p['scheme']) === 'https' ? 443 : 80)) {
        throw new InvalidArgumentException('原网页 URL 不允许使用非标准端口');
    }
    return $url;
}

function import_srcset(string $value): array
{
    $candidates = [];
    foreach (explode(',', $value) as $item) {
        $tokens = preg_split('/\s+/', trim($item)) ?: [];
        if (!$tokens || $tokens[0] === '') continue;
        $weight = 1.0;
        if (preg_match('/^(\d+)w$/', $tokens[1] ?? '', $m)) $weight = (float)$m[1];
        if (preg_match('/^(\d+(?:\.\d+)?)x$/', $tokens[1] ?? '', $m)) $weight = (float)$m[1] * 1000;
        $candidates[] = ['url' => $tokens[0], 'weight' => $weight];
    }
    usort($candidates, static fn($a, $b) => $b['weight'] <=> $a['weight']);
    return array_column($candidates, 'url');
}
