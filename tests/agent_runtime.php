<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$report=agent_runtime_report(database());
foreach(['mode','php','server_time','checks','queues','enabled','notes'] as $key) {
    if (!array_key_exists($key,$report)) throw new RuntimeException('Missing diagnostic field: '.$key);
}
foreach(['php_dom','php_mbstring','ffmpeg','ffmpeg_libx264','ffmpeg_drawtext','font_cjk',
          'media_directory','image_directory','ai_configured','search_api'] as $key) {
    if (!isset($report['checks'][$key]) || !is_bool($report['checks'][$key]['ok'])) {
        throw new RuntimeException('Diagnostic result is missing or malformed: '.$key);
    }
}
$encoded=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
if (str_contains($encoded,'Bearer ') || str_contains($encoded,'api_key_cipher') ||
    str_contains($encoded,'password_hash')) {
    throw new RuntimeException('Diagnostic output potentially exposed secret material');
}
if (!is_int($report['queues']['queued']) || !is_int($report['queues']['review'])) {
    throw new RuntimeException('Queue diagnostics are not numeric');
}
echo "Agent runtime diagnostic passed: PHP, FFmpeg capability, media paths, private queue and safe config flags\n";
