<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$dir = sys_get_temp_dir() . '/howhub-diag-ci-' . bin2hex(random_bytes(6));
putenv('HOWHUB_DIAG_TEST_DIR=' . $dir);
$_SERVER['REQUEST_URI'] = '/admin/guides/save?api_key=SECRET-NOT-IN-LOG';
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    if (diag_version()['release'] !== trim(file_get_contents(HOWHUB_ROOT . '/VERSION'))) throw new RuntimeException('Version not detected');
    diag_event('info', 'guides', 'save', 'success', ['guide_id' => 42, 'message' => 'token=SECRET-NOT-IN-LOG']);
    diag_exception('ai', 'model_sync', new RuntimeException('Bearer top-secret sk-very-secret https://private.invalid/v1?key=secret'));
    $items = diag_entries(10);
    if (count($items) !== 2 || $items[0]['feature'] !== 'ai' || $items[1]['details']['guide_id'] !== 42) throw new RuntimeException('Logging entries failed');
    $raw = file_get_contents($dir . '/events.jsonl');
    foreach (['SECRET-NOT-IN-LOG','top-secret','sk-very-secret','private.invalid','?api_key='] as $secret) {
        if (str_contains($raw, $secret)) throw new RuntimeException('Log leaked secrets');
    }
    if (!str_contains($raw, 'model_sync') || !str_contains($raw, '"version"') || !str_contains($raw, 'request_id')) throw new RuntimeException('Missing structured fields');
    if (diag_entries(10, 'critical') !== [] || count(diag_entries(10, 'error', 'ai')) !== 1) throw new RuntimeException('Filters failed');
    // At least one actual rotation is expected; 5 x 1MB bounds disk usage.
    for ($i = 0; $i < 6000; $i++) diag_event('info', 'system', 'rotation_test', 'success', ['message' => str_repeat('x', 100)]);
    $files = glob($dir . '/events.jsonl*') ?: [];
    if (count($files) > HH_DIAG_HISTORY + 1 || count($files) < 2) throw new RuntimeException('Rotation bounded count failed');
    echo "HowHub diagnostics passed: version / redaction / filtering / request id / rotation\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
    foreach (glob($dir . '/.*') ?: [] as $file) { if (basename($file) !== '.' && basename($file) !== '..') @unlink($file); }
    @rmdir($dir);
    putenv('HOWHUB_DIAG_TEST_DIR');
}
