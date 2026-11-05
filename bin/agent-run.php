<?php
declare(strict_types=1);

/** Scheduled by the hosting control panel, never by a long-lived web process. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
$lockFile=HOWHUB_ROOT.'/storage/.agent-scheduler.lock';
$lock=fopen($lockFile,'c');
if ($lock===false || !flock($lock,LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT,"{\"status\":\"busy\"}\n");
    exit(0);
}
try {
    $pdo=database();
    $result=agent_tick($pdo);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT),"\n";
} catch (Throwable $e) {
    diag_exception('discovery_agent','tick',$e);
    fwrite(STDERR,'HowHub Agent tick failed: '.get_class($e)."\n");
    exit(1);
} finally {
    flock($lock,LOCK_UN);
    fclose($lock);
}
