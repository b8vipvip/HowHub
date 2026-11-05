<?php
declare(strict_types=1);

/**
 * Bounded public HTTP(S) reader for administrator-requested URL imports.
 * Resolve and pin the IP separately for *every* redirect. Do not use a proxy,
 * system cookies, ambient credentials, browser sessions or script execution.
 */
function url_import_fetch(string $url, int $limit = 2097152, ?callable $fixture = null, array $acceptableStatuses = [], string $userAgent = 'HowHub-URL-Import/1.0', bool $sameHostRedirects = false): array
{
    if ($limit < 1 || $limit > 40 * 1024 * 1024) throw new InvalidArgumentException('下载限制无效');
    $url = import_page_url($url);
    $firstHost = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (strlen($userAgent) > 160 || !preg_match('/^[A-Za-z0-9 ._()\/+;:=-]+$/D', $userAgent)) throw new InvalidArgumentException('User-Agent 格式无效');
    for ($hop = 0; $hop < 4; $hop++) {
        if ($fixture !== null) {
            $response = $fixture($url);
            if (!is_array($response) || !isset($response['status'], $response['body'])) {
                throw new RuntimeException('测试 HTTP 返回格式错误');
            }
            $status = (int)$response['status'];
            $headers = array_change_key_case((array)($response['headers'] ?? []), CASE_LOWER);
            $body = (string)$response['body'];
            if (strlen($body) > $limit) throw new RuntimeException('下载内容超过大小限制');
        } else {
            [$host, $port, $ip] = media_remote_target($url);
            if (!extension_loaded('curl')) throw new RuntimeException('需要 PHP curl 扩展');
            $body = '';
            $headers = [];
            $tooLarge = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
                CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept: text/html,text/plain,application/pdf,application/json,video/mp4,image/*;q=0.8,*/*;q=0.1'],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    if (str_starts_with(strtolower($line), 'http/')) $headers = [];
                    $pos = strpos($line, ':');
                    if ($pos !== false) {
                        $name = strtolower(trim(substr($line, 0, $pos)));
                        if (in_array($name, ['location','content-type','content-length'], true)) {
                            $headers[$name] = trim(substr($line, $pos + 1));
                        }
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $limit, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > $limit) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($tooLarge) throw new RuntimeException('来源文件超过 ' . (int)ceil($limit / 1048576) . 'MB 限制，无法安全导入');
            if (!$ok) throw new RuntimeException('获取来源网页失败或超时，请确认该网页可公开访问');
        }
        if (in_array($status, [301,302,303,307,308], true)) {
            $location = trim((string)($headers['location'] ?? ''));
            if ($location === '') throw new RuntimeException('网页跳转缺少目标地址');
            $next = import_resolve_url($location, $url);
            if ($next === '') throw new RuntimeException('网页跳转至不安全或不支持的地址');
            $url = import_page_url(preg_replace('/#.*$/', '', $next) ?? $next);
            if ($sameHostRedirects && strtolower((string)parse_url($url, PHP_URL_HOST)) !== $firstHost) throw new RuntimeException('自动发现禁止跨网站跳转');
            continue;
        }
        if ($status !== 200 && !in_array($status, $acceptableStatuses, true)) throw new RuntimeException('来源网站返回 HTTP ' . $status . '，无法导入；可能要求登录或限制采集');
        if ($body === '' && $status === 200) throw new RuntimeException('来源网页返回空内容');
        $type = strtolower(trim(explode(';', (string)($headers['content-type'] ?? ''), 2)[0]));
        return ['url'=>$url,'body'=>$body,'type'=>$type,'bytes'=>strlen($body),'status'=>$status,'headers'=>$headers];
    }
    throw new RuntimeException('网页跳转次数超过上限，已停止导入');
}
