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
    // A second process holding the diagnostics lock must never block requests.
    $ready = $dir . '/lock-held.signal';
    $holdCode = '$handle=fopen($argv[1],"c"); if(!$handle || !flock($handle, LOCK_EX)) exit(2); file_put_contents($argv[2], "held"); usleep(2500000); flock($handle, LOCK_UN); fclose($handle);';
    $hold = proc_open([PHP_BINARY, '-r', $holdCode, $dir . '/.lock', $ready],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $handles);
    if (!is_resource($hold)) throw new RuntimeException('Cannot launch held-lock test');
    fclose($handles[0]);
    try {
        $held = false;
        for ($attempt = 0; $attempt < 70; ++$attempt) {
            if (is_file($ready)) { $held = true; break; }
            usleep(20000);
        }
        if (!$held) throw new RuntimeException('Lock holder did not initialize');
        $start = microtime(true);
        diag_event('info', 'system', 'held_lock_test', 'success');
        $elapsed = microtime(true) - $start;
        if ($elapsed >= 0.35) throw new RuntimeException('Diagnostics blocked on held lock for ' . $elapsed . ' seconds');
        if (count(diag_entries(10)) !== 2) throw new RuntimeException('Failed to skip contested telemetry record');
    } finally {
        proc_terminate($hold);
        foreach ([$handles[1], $handles[2]] as $pipe) fclose($pipe);
        proc_close($hold);
        @unlink($ready);
    }
    // Once contention has ended, diagnostics still work.
    diag_event('info', 'system', 'lock_recovered', 'success');
    if ((diag_entries(1)[0]['action'] ?? '') !== 'lock_recovered') throw new RuntimeException('Diagnostics did not recover after contention');
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
