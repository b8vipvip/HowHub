<?php
// Test-only OpenAI-compatible chat2api fixture. Never deploy it as a web route.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sk-ci-local-token') {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'Unauthorized']]);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/v1/models') {
    echo json_encode(['data' => [['id' => 'ci-demo'], ['id' => 'gpt-6-sol']]]);
    exit;
}
if ($path === '/v1/chat/completions') {
    $post = json_decode(file_get_contents('php://input'), true);
    if (!is_array($post) || $post['model'] !== 'ci-demo' || $post['stream'] !== false) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'Invalid request']]);
        exit;
    }
    $input = (string)($post['messages'][1]['content'] ?? '');
    if (str_contains($input, '[[HH_IMAGE_01]]')) {
        $html = '<div class="hh-step"><h2 style="color:#6753cc">优化后的步骤</h2><p class="hh-tip">按原顺序完成绑定</p><figure>[[HH_IMAGE_01]]</figure>';
        if (str_contains($input, '[[HH_IMAGE_02]]')) $html .= '<figure>[[HH_IMAGE_02]]</figure>';
        $html .= '</div><script>alert(1)</script>';
        $result = ['body_html' => $html, 'image_sizes' => ['medium', 'small']];
        echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)]]]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = ['title' => '模拟生成的教程', 'summary' => '从网页提取的步骤',
        'tags' => '测试,教程', 'body_html' => '<h2>第一步</h2><p>点击首页</p>[[IMAGE_1]]<h2>第二步</h2><p>扫码绑定</p>[[IMAGE_2]]'];
    echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)]]]], JSON_UNESCAPED_UNICODE);
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'Not found']);
