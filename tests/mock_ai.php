<?php
// Test-only OpenAI-compatible chat2api fixture. Never deploy it as a web route.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sk-ci-local-token') {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'Unauthorized']]);
    exit;
}
function mock_reply(array $result, bool $stream): void
{
    $content = json_encode($result, JSON_UNESCAPED_UNICODE);
    if (!$stream) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]], JSON_UNESCAPED_UNICODE);
        return;
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    $chunks = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_chunk($chunks, max(1, (int)ceil(count($chunks) / 4)));
    foreach ($parts as $p) {
        echo 'data: ' . json_encode(['choices'=>[['delta'=>['content'=>implode('', $p)]]]], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        usleep(50000);
    }
    echo "data: [DONE]\n\n";
    flush();
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/v1/models') {
    echo json_encode(['data' => [['id' => 'ci-demo'], ['id' => 'gpt-6-sol']]]);
    exit;
}
if ($path === '/v1/chat/completions') {
    $post = json_decode(file_get_contents('php://input'), true);
    if (!is_array($post) || $post['model'] !== 'ci-demo' || !is_bool($post['stream'] ?? null)) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'Invalid request']]);
        exit;
    }
    $input = (string)($post['messages'][1]['content'] ?? '');
    $stream = $post['stream'] === true;
    if (str_contains($input, '[[HH_IMAGE_01]]')) {
        $html = '<div class="hh-step"><h2 style="color:#6753cc">优化后的步骤</h2><p class="hh-tip">按原顺序完成绑定</p><figure>[[HH_IMAGE_01]]</figure>';
        if (str_contains($input, '[[HH_IMAGE_02]]')) $html .= '<figure>[[HH_IMAGE_02]]</figure>';
        $html .= '</div><script>alert(1)</script>';
        $result = ['body_html' => $html, 'image_sizes' => ['medium', 'small']];
        mock_reply($result, $stream);
        exit;
    }
    $result = ['title' => '模拟生成的教程', 'summary' => '从网页提取的步骤',
        'tags' => '测试,教程', 'body_html' => '<h2>第一步</h2><p>点击首页</p>[[IMAGE_1]]<h2>第二步</h2><p>扫码绑定</p>[[IMAGE_2]]'];
    mock_reply($result, $stream);
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'Not found']);
