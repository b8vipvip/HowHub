<?php
declare(strict_types=1);

// Bounded, private JSONL telemetry. Never record POST bodies, cookies, credentials, prompt text or full URLs.
const HH_DIAG_MAX_BYTES = 1048576; // ~1 MB x 5 files
const HH_DIAG_HISTORY = 4;

function diag_dir(): string
{
    // Test isolation only: production web requests can never override the private storage path.
    $test = PHP_SAPI === 'cli' ? getenv('HOWHUB_DIAG_TEST_DIR') : false;
    return is_string($test) && str_starts_with($test, sys_get_temp_dir() . '/') ? $test : HOWHUB_ROOT . '/storage/diagnostics';
}

function diag_version(): array
{
    static $version = null;
    if ($version !== null) return $version;
    $tag = is_file(HOWHUB_ROOT . '/VERSION') ? trim((string)file_get_contents(HOWHUB_ROOT . '/VERSION')) : 'unknown';
    $sha = '';
    $git = HOWHUB_ROOT . '/.git';
    if (is_file($git . '/HEAD')) {
        $head = trim((string)@file_get_contents($git . '/HEAD'));
        if (preg_match('/^[a-f0-9]{40}$/i', $head)) $sha = $head;
        elseif (preg_match('~^ref: (refs/[A-Za-z0-9/_-]+)$~', $head, $m)) {
            $file = $git . '/' . $m[1];
            if (is_file($file)) $sha = trim((string)@file_get_contents($file));
            elseif (is_file($git . '/packed-refs')) {
                $lines = file($git . '/packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    if (preg_match('~^([a-f0-9]{40}) ' . preg_quote($m[1], '~') . '$~i', $line, $found)) $sha = $found[1];
                }
            }
        }
    }
    $version = ['release' => preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/', $tag) ? $tag : 'unknown', 'commit' => preg_match('/^[a-f0-9]{40}$/i', $sha) ? substr($sha, 0, 12) : 'unavailable'];
    return $version;
}

function diag_request_id(): string
{
    static $id = null;
    if ($id === null) $id = bin2hex(random_bytes(6));
    return $id;
}

function diag_route(): string
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($path)) return 'cli';
    // Never save raw unknown paths: they may contain authentication material.
    if (preg_match('~^/guide/[^/]+$~', $path)) return '/guide/:slug';
    if (preg_match('~^/file/\d+$~', $path)) return '/file/:id';
    $known = ['/', '/healthz', '/admin', '/admin/login', '/admin/logout', '/admin/ai-settings', '/admin/ai/models', '/admin/import/clean', '/admin/import/generate', '/admin/import/generate/stream', '/admin/guides/optimize', '/admin/guides/optimize/stream', '/admin/upload', '/admin/media/list', '/admin/media/action', '/admin/media', '/admin/guides', '/admin/guides/new', '/admin/guides/edit', '/admin/guides/delete', '/admin/guides/save', '/admin/categories', '/admin/settings', '/admin/password', '/admin/diagnostics', '/admin/diagnostics/export', '/admin/diagnostics/client'];
    return in_array($path, $known, true) ? $path : '/other';
}

function diag_redact(string $value): string
{
    $value = strip_tags($value);
    $value = preg_replace('~https?://\S+~i', '[URL]', $value) ?? '';
    $value = preg_replace('~(?i)(bearer\s+|sk-[A-Za-z0-9._-]{4,}|(?:api[_-]?key|password|token|secret)\s*[:=]\s*)\S+~', '[REDACTED]', $value) ?? '';
    $value = preg_replace('~[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}~', '[EMAIL]', $value) ?? '';
    $value = preg_replace('~(?<!\d)1[3-9]\d{9}(?!\d)~', '[PHONE]', $value) ?? '';
    $value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value) ?? '';
    return function_exists('mb_substr') ? mb_substr(trim($value), 0, 180, 'UTF-8') : substr(trim($value), 0, 180);
}

function diag_event(string $level, string $feature, string $action, string $result = 'success', array $details = []): void
{
    if (!in_array($level, ['info', 'warning', 'error', 'critical'], true)) $level = 'warning';
    $feature = preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $feature) ? $feature : 'system';
    $action = preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $action) ? $action : 'unknown';
    $result = in_array($result, ['success', 'failure', 'blocked', 'start'], true) ? $result : 'failure';
    $extra = [];
    foreach (['guide_id', 'asset_id', 'count', 'size_bytes', 'duration_ms', 'http_code', 'upstream_status'] as $field) {
        if (isset($details[$field]) && is_numeric($details[$field])) $extra[$field] = max(0, min((int)$details[$field], 2000000000));
    }
    if (isset($details['exception'])) $extra['exception'] = preg_match('/^[A-Za-z_][A-Za-z_0-9\\\\]{0,80}$/', (string)$details['exception']) ? (string)$details['exception'] : 'Exception';
    if (isset($details['message'])) $extra['message'] = diag_redact((string)$details['message']);
    if (isset($details['kind']) && in_array($details['kind'], ['image', 'file'], true)) $extra['kind'] = $details['kind'];
    try {
        $dir = diag_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return;
        $lock = @fopen($dir . '/.lock', 'c');
        if (!$lock) return;
        if (!flock($lock, LOCK_EX)) { fclose($lock); return; }
        $current = $dir . '/events.jsonl';
        if (is_file($current) && filesize($current) >= HH_DIAG_MAX_BYTES) {
            for ($n = HH_DIAG_HISTORY; $n >= 1; $n--) {
                $from = $n === 1 ? $current : $dir . '/events.jsonl.' . ($n - 1);
                $to = $dir . '/events.jsonl.' . $n;
                if (is_file($from)) @rename($from, $to);
            }
        }
        $record = [
            'time' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'feature' => $feature,
            'action' => $action, 'result' => $result,
            'request_id' => diag_request_id(), 'method' => in_array($_SERVER['REQUEST_METHOD'] ?? 'CLI', ['GET', 'POST', 'PUT', 'DELETE', 'CLI'], true) ? ($_SERVER['REQUEST_METHOD'] ?? 'CLI') : 'OTHER',
            'route' => diag_route(), 'status_code' => $extra['http_code'] ?? (http_response_code() ?: 200),
            'version' => diag_version(), 'details' => $extra,
        ];
        @file_put_contents($current, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        flock($lock, LOCK_UN);
        fclose($lock);
    } catch (Throwable $ignore) {
        // Fail open: telemetry must never interrupt the site or expose credentials in error output.
    }
}

function diag_exception(string $feature, string $action, Throwable $error): void
{
    $code = http_response_code();
    if ($code < 400) $code = $error instanceof PDOException ? 500 : 400;
    diag_event('error', $feature, $action, 'failure', ['exception' => get_class($error), 'message' => $error->getMessage(), 'http_code' => $code]);
}

function diag_install(): void
{
    static $installed = false;
    if ($installed || PHP_SAPI === 'cli') return;
    $installed = true;
    $start = microtime(true);
    header('X-HowHub-Request-ID: ' . diag_request_id());
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) return false;
        // PHP warnings and notices are often recoverable, still useful for diagnosing a feature.
        diag_event(in_array($severity, [E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR], true) ? 'warning' : 'info', 'php', 'runtime_notice', 'failure', ['message' => $message]);
        return false;
    });
    register_shutdown_function(static function () use ($start): void {
        $last = error_get_last();
        if ($last && in_array((int)$last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            diag_event('critical', 'php', 'fatal', 'failure', ['message' => (string)$last['message'], 'http_code' => http_response_code() ?: 500]);
        }
        $code = http_response_code() ?: 200;
        $route = diag_route();
        if ($code >= 400 || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with($route, '/admin'))) {
            diag_event($code >= 500 ? 'error' : ($code >= 400 ? 'warning' : 'info'), 'http', 'request_end', $code >= 400 ? 'failure' : 'success', ['http_code' => $code, 'duration_ms' => (int)((microtime(true) - $start) * 1000)]);
        }
    });
}

function diag_entries(int $limit = 200, string $level = '', string $feature = ''): array
{
    $limit = max(1, min(2000, $limit));
    $out = [];
    $dir = diag_dir();
    $files = array_merge([$dir . '/events.jsonl'], array_map(static fn($n) => $dir . '/events.jsonl.' . $n, range(1, HH_DIAG_HISTORY)));
    foreach ($files as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        // Total data at most ~5MB; read lines in reverse order without database writes.
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $item = json_decode($lines[$i], true);
            if (!is_array($item)) continue;
            if ($level !== '' && ($item['level'] ?? '') !== $level) continue;
            if ($feature !== '' && ($item['feature'] ?? '') !== $feature) continue;
            $out[] = $item;
            if (count($out) >= $limit) break 2;
        }
    }
    return $out;
}

function diag_health(): array
{
    $required = ['pdo_sqlite', 'mbstring', 'dom', 'fileinfo', 'curl', 'openssl'];
    $extensions = [];
    foreach ($required as $name) $extensions[$name] = extension_loaded($name);
    $storage = is_dir(HOWHUB_ROOT . '/storage') && is_writable(HOWHUB_ROOT . '/storage');
    $uploads = is_dir(HOWHUB_ROOT . '/public/uploads') && is_writable(HOWHUB_ROOT . '/public/uploads');
    $logdir = is_dir(diag_dir()) ? is_writable(diag_dir()) : $storage;
    $disk = @disk_free_space(HOWHUB_ROOT);
    $diskOK = $disk === false || $disk > 100 * 1024 * 1024;
    $checks = ['storage' => $storage, 'uploads' => $uploads, 'diagnostics' => $logdir, 'disk_space' => $diskOK];
    foreach ($extensions as $name => $ok) $checks['ext_' . $name] = $ok;
    return [
        'version' => diag_version(), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
        'checks' => $checks, 'status' => in_array(false, $checks, true) ? 'degraded' : 'healthy',
        'disk_free_mb' => $disk === false ? null : round($disk / 1048576),
        'ai_configured' => diag_ai_configured(),
    ];
}

function diag_ai_configured(): bool
{
    if (!function_exists('ai_config')) return false;
    try { return (bool)(ai_config()['configured'] ?? false); }
    catch (Throwable $error) { diag_exception('ai', 'health_config', $error); return false; }
}
