<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$text = '';
$done = false;
$received = [];
$onDelta = static function (string $delta) use (&$received): void {$received[] = $delta;};
foreach ([
    'data: {"choices":[{"delta":{"content":"你好"}}]}',
    'data: {"choices":[{"delta":{"content":" 世界"}}]}',
    'data: [DONE]',
] as $frame) ai_stream_frame($frame, $onDelta, $text, $done);
if ($text !== '你好 世界' || $received !== ['你好', ' 世界'] || !$done) throw new RuntimeException('SSE delta parsing failed');
try {ai_stream_frame('data: {"error":{"message":"secret"}}', $onDelta, $text, $done);throw new RuntimeException('expected error');}
catch (RuntimeException $e) {if (str_contains($e->getMessage(), 'secret')) throw new RuntimeException('Provider secret leaked');}

/**
 * Regression: cURL's documented progress option is CURLOPT_PROGRESSFUNCTION.
 * Some PHP builds never define CURLOPT_XFERINFOFUNCTION; the live request
 * must not reference an undefined constant before reaching the provider.
 */
$heartbeat = microtime(true) - 7;
$generated = '';
$seen = [];
$options = ai_stream_progress_options(
    static function (string $stage) use (&$seen): void { $seen[] = $stage; },
    $generated,
    $heartbeat
);
if (!extension_loaded('curl')) {
    if ($options !== []) throw new RuntimeException('Missing curl must not select progress constants');
} elseif (defined('CURLOPT_NOPROGRESS') && defined('CURLOPT_PROGRESSFUNCTION')) {
    $progressName = defined('CURLOPT_XFERINFOFUNCTION') ? 'CURLOPT_XFERINFOFUNCTION' : 'CURLOPT_PROGRESSFUNCTION';
    $progressKey = constant($progressName);
    if (!array_key_exists($progressKey, $options) || ($options[CURLOPT_NOPROGRESS] ?? true) !== false) {
        throw new RuntimeException('Safe progress callback option missing');
    }
    $callback = $options[$progressKey];
    if ($callback(null, 0, 0, 0, 0) !== 0 || count($seen) !== 1) {
        throw new RuntimeException('Progress callback must safely report heartbeat');
    }
}

echo "HowHub streaming parser test passed\n";
