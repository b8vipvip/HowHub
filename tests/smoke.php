<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = database();
$guide = $pdo->query("SELECT * FROM guides WHERE slug='tcl-leiniao-kugou-account'")->fetch();
if (!$guide) throw new RuntimeException('首篇示例教程缺失');
if (substr_count($guide['body_html'], '<img ') !== 5) throw new RuntimeException('内置配图不是五张');
if (substr_count($guide['body_html'], '<h2>') !== 3) throw new RuntimeException('应当有三个步骤');
if (!str_contains($guide['body_html'], '不要用微信')) throw new RuntimeException('重要提示缺失');
if (!str_contains($guide['body_html'], '不要点微信图标绑定')) throw new RuntimeException('绑定提示缺失');
if (!str_contains(make_slug('绑定 酷狗 账号'), '绑定-酷狗-账号')) throw new RuntimeException('中文网址别名失败');
$dirty = '<p onclick="alert(1)">安全文本</p><script>alert(1)</script><img src="javascript:alert(1)"><a href="javascript:alert(1)">危险</a><a href="https://example.com">安全</a>';
$clean = sanitize_content($dirty);
foreach (['onclick', '<script', 'javascript:'] as $unsafe) {
    if (str_contains($clean, $unsafe)) throw new RuntimeException('未过滤危险内容 ' . $unsafe);
}
[$html, $toc] = render_with_toc($guide['body_html']);
if (count($toc) !== 3 || !str_contains($html, 'id="section-1"')) throw new RuntimeException('目录生成失败');
echo "HowHub smoke passed: seed / images / slug / sanitize / TOC\n";
// Asset migration and editor media class whitelist.
if (!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='media_assets'")->fetchColumn()) throw new RuntimeException('素材表没有建立');
$img = sanitize_content('<figure><img src="/uploads/test/test.png" class="guide-img-small" style="position:absolute" onerror="x"></figure>');
if (!str_contains($img, 'class="guide-img-small"') || str_contains($img, 'onerror') || str_contains($img, 'style=')) throw new RuntimeException('图片尺寸或安全过滤异常');
if (str_contains(sanitize_content('<img src="/uploads/test.png" class="evil">'), 'class="evil"')) throw new RuntimeException('图片非白名单 class 进入正文');
$directory = HOWHUB_ROOT . '/public/uploads/ci-tests';
if (!is_dir($directory)) mkdir($directory, 0775, true);
$mockPath = $directory . '/tiny.gif';
file_put_contents($mockPath, base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='));
try {
    $asset = media_register($pdo, 'image', 'ci-tests/tiny.gif', 'tiny.gif', 'image/gif', filesize($mockPath));
    if ($asset['kind'] !== 'image' || !str_starts_with($asset['url'], '/uploads/')) throw new RuntimeException('素材注册不正确');
    $pdo->prepare('UPDATE guides SET cover_image=? WHERE id=?')->execute([$asset['url'], $guide['id']]);
    try {
        media_delete($pdo, (int)$asset['id']);
        throw new RuntimeException('删除在用素材应被阻止');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), '引用')) throw $e;
    }
    $pdo->prepare('UPDATE guides SET cover_image=? WHERE id=?')->execute([$guide['cover_image'], $guide['id']]);
    media_delete($pdo, (int)$asset['id']);
    if (file_exists($mockPath)) throw new RuntimeException('删除素材后物理文件仍存在');
} finally {
    $pdo->prepare('UPDATE guides SET cover_image=? WHERE id=?')->execute([$guide['cover_image'], $guide['id']]);
    @unlink($mockPath);
    @rmdir($directory);
}
echo "HowHub media smoke passed: migration / image sizing / safe delete\n";
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO guides(title,slug,body_html,status) VALUES('附件引用匹配','ci-media-reference-check','<p><a href=\"/file/10\">下载</a></p>','published')")->execute();
    if (media_usage_published($pdo, '/file/1') !== 0 || media_usage_published($pdo, '/file/10') !== 1) {
        throw new RuntimeException('附件访问权限引用匹配不精确');
    }
} finally {
    $pdo->rollBack();
}

// Reuse mv3c's base URL contract and ensure API keys stay encrypted server-side.
foreach (['https://api.example.com/v1', 'http://127.0.0.1:8080/v1'] as $url) {
    if (ai_base_url($url) !== $url) throw new RuntimeException('AI 地址解析异常');
}
foreach (['http://api.example.com/v1', 'https://user:pass@example.com/v1', 'https://api.example.com/other', 'https://api.example.com/v1?key=abc'] as $bad) {
    try { ai_base_url($bad); throw new RuntimeException('错误接受危险地址'); }
    catch (InvalidArgumentException $e) { /* expected */ }
}
putenv('HOWHUB_AI_SECRET_KEY=' . str_repeat('ab', 32));
$secretText = 'sk-example-secret-test';
$encrypted = ai_encrypt($secretText);
if (str_contains($encrypted, $secretText) || ai_decrypt($encrypted) !== $secretText) throw new RuntimeException('AI 凭据加解密失败');
putenv('HOWHUB_AI_SECRET_KEY');

// The sample copied from Yuque uses ne-card, escaped angle brackets and five remote images.
$urls = [];
$markup = '\\<div class="ne-viewer-body">\\<ne-p>第一步：打开电视音乐会员页面\\</ne-p>';
for ($i = 0; $i < 5; ++$i) {
    $url = 'https://cdn.nlark.com/yuque/0/2025/png/example-' . ($i + 1) . '.png';
    $urls[] = $url;
    $markup .= '\\<ne-card>\\<img src="' . str_replace('://', '\\://', $url) . '">\\</ne-card>';
}
$markup .= '\\<script>document.cookie</script>\\<ne-p>不要用微信扫码\\</ne-p>\\</div>';
$source = import_prepare_source($markup);
if (count($source['images']) !== 5 || !str_contains($source['content'], '不要用微信') || str_contains($source['content'], 'document.cookie')) {
    throw new RuntimeException('网页源码解析未完整保留图片或没有剔除脚本');
}
foreach ($urls as $i => $url) {
    if ($source['images'][$i]['src'] !== $url || !str_contains($source['content'], '[[IMAGE_' . ($i + 1) . ']]')) {
        throw new RuntimeException('网页素材图片顺序/网址失真');
    }
}
$draft = import_safe_result([
    'title' => '电视音乐教程',
    'summary' => '演示步骤',
    'tags' => '雷鸟,酷狗',
    'body_html' => '<h2>第一步</h2><p>打开电视</p>' . implode('', array_map(static fn($n) => '<p>[[IMAGE_' . $n . ']]</p>', range(1, 5))),
], $source);
if (substr_count($draft['body'], '<img ') !== 5 || $draft['cover'] !== $urls[0]) throw new RuntimeException('AI 图文草稿缺图');
$dangerousDraft = import_safe_result([
    'title' => '安全教程', 'body_html' => '<script>alert(1)</script><h2>步骤</h2><p>内容文字</p><img src="https://attacker.example/sneak.png">[[IMAGE_1]]',
], $source);
if (str_contains($dangerousDraft['body'], 'attacker.example') || str_contains($dangerousDraft['body'], '<script')) {
    throw new RuntimeException('AI 模型输出绕过安全清理');
}
echo "HowHub AI import smoke passed: base URL / key encryption / five Yuque images / sanitization\n";
