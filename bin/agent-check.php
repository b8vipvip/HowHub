<?php
declare(strict_types=1);

/** php bin/agent-check.php : no external AI calls, public HTML or deployments. */
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/app/bootstrap.php';
try {
    $report=agent_runtime_report(database());
    echo json_encode($report,
        JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),"\n";
} catch(Throwable $e) {
    fwrite(STDERR,'Agent runtime diagnostic failed: '.get_class($e)."\n");
    exit(1);
}
