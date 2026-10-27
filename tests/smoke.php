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
