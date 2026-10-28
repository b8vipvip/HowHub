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
echo "HowHub streaming parser test passed\n";
