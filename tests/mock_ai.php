<?php
// Test-only OpenAI-compatible chat2api fixture. Never deploy it as a web route.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sk-ci-local-token') {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'Unauthorized']]);
    exit;
}
function mock_reply(array|string $result, bool $stream): void
{
    $content = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE);
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
    if (!$stream && str_contains($input, 'CI_SLOW_IMPORT_2026')) {
        $signal = getenv('HOWHUB_CI_SLOW_SIGNAL') ?: '';
        if ($signal !== '') file_put_contents($signal, 'ready');
        usleep(3500000);
    }
    if (str_contains($input, '待评估内容：')) {
        $isAd = str_contains($input, 'CI_AGENT_AD_ONLY');
        mock_reply([
            'useful'=>!$isAd,
            'score'=>$isAd ? 12 : 92,
            'title'=>$isAd ? '广告介绍' : '操作方法与问题解答',
            'summary'=>$isAd ? '没有清晰操作步骤' : '这份来自原始技术文档的内容详细说明了设备连接、账号绑定、状态核对和常见失败原因的处理方式。读者可根据自己的设备状态逐步检查网络配置，操作之前应核对具体型号与官方说明，避免未经证实的设置影响系统正常运行。',
            'key_points'=>$isAd ? [] : ['先检查设备和网络状态，再根据型号进入设置', '根据提示绑定账号并确认最终连接结果'],
            'concerns'=>['AI 根据单一来源整理，尚未独立核实'],
            'category'=>'信息技术',
            'language'=>'English',
        ], $stream);
        exit;
    }
    if (str_contains($input, '内容来源：')) {
        $notTutorial = str_contains($input, 'CI_NOT_GUIDE');
        mock_reply([
            'is_tutorial' => !$notTutorial,
            'reason' => $notTutorial ? '只有商品广告，没有操作步骤' : '包含可验证的设备操作步骤',
            'suggested_title' => $notTutorial ? '' : '从来源导入的操作教程',
        ], $stream);
        exit;
    }
    if (str_contains($input, '[[HH_IMAGE_01]]')) {
        $html = '<div class="hh-step"><h2 style="color:#6753cc">优化后的步骤</h2><p class="hh-tip">按原顺序完成绑定</p><figure>[[HH_IMAGE_01]]</figure>';
        if (str_contains($input, '[[HH_IMAGE_02]]')) $html .= '<figure>[[HH_IMAGE_02]]</figure>';
        $html .= '</div><script>alert(1)</script>';
        $result = ['body_html' => $html, 'image_sizes' => ['medium', 'small']];
        mock_reply($result, $stream);
        exit;
    }
    // HTML import returns raw HTML, not a JSON object.
    $result = '<h2 style="color:#6753cc">第一步</h2><p>点击首页</p>';
    if (str_contains($input, '[[IMAGE_1]]')) $result .= '<figure>[[IMAGE_1]]</figure>';
    $result .= '<h2>第二步</h2><p>扫码绑定</p>';
    if (str_contains($input, '[[IMAGE_2]]')) $result .= '<figure>[[IMAGE_2]]</figure>';
    if (str_contains($input, '[[LINK_1]]')) $result .= '<p>原文参考：[[LINK_1]]</p>';
    if (str_contains($input, '[[LINK_2]]')) $result .= '<p>其他资料：[[LINK_2]]</p>';
    $result .= '<script>alert(1)</script><a href="javascript:alert(1)">不安全链接</a>';
    mock_reply($result, $stream);
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'Not found']);
