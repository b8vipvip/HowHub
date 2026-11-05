<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$sourceUrl = 'https://docs.example.com/guides/setup/index.html';
$markup = <<<'HTML'
<html><body>
<nav>导航不要出现在正文</nav>
<main><article>
<h2>设备连接和账号绑定教程</h2>
<p>请先打开软件并检查提示。</p>
<picture>
  <source srcset="../../assets/one-small.webp 400w, ../../assets/one-large.webp 1200w">
  <img src="data:image/svg+xml;base64,invalid" alt="响应式图片">
</picture>
<div data-background-image="../images/background.jpg">操作界面示例</div>
<img data-original="/media/screenshot.png" src="https://docs.example.com/blank.gif" alt="原图优先">
<img srcset="/media/low.jpg 300w, /media/high.jpg 900w" alt="清晰度优先">
<p><a href="../help/contact.html">联系客服说明</a></p>
<p><a href="https://service.example.org/support">查看官方帮助</a></p>
<a href="/download/steps.html"><img src="//cdn.example.com/linked.png" alt="可点击图片"></a>
</article></main><footer>页脚不要出现</footer>
</body></html>
HTML;
$source = import_prepare_source($markup, $sourceUrl);
$expected = [
    'https://docs.example.com/assets/one-large.webp',
    'https://docs.example.com/guides/images/background.jpg',
    'https://docs.example.com/media/screenshot.png',
    'https://docs.example.com/media/high.jpg',
    'https://cdn.example.com/linked.png',
];
if (array_column($source['images'], 'src') !== $expected) throw new RuntimeException('通用图片属性/相对 URL/srcset 解析失败');
if (count($source['links']) !== 2 ||
    $source['links'][0]['href'] !== 'https://docs.example.com/guides/help/contact.html' ||
    $source['images'][4]['href'] !== 'https://docs.example.com/download/steps.html') {
    throw new RuntimeException('超链接地址或链接图片未正确提取');
}
if (str_contains($source['content'], '导航不要') || str_contains($source['content'], '页脚不要')) {
    throw new RuntimeException('提取正文混入导航或页脚');
}
preg_match_all('/\[\[(?:IMAGE|LINK)_\d+\]\]/', $source['content'], $resourceTokens);
$markers = implode('', array_map(static fn($marker) => '<p>' . $marker . '</p>', $resourceTokens[0]));
$html = import_safe_html_output('<h2>整理的步骤</h2>' . $markers . '<a href="https://attacker.invalid">伪造外链</a>', $source);
if (substr_count($html, '<img ') !== 5 || substr_count($html, '<a href=') !== 3 ||
    str_contains($html, 'attacker.invalid') ||
    !str_contains($html, 'https://docs.example.com/guides/help/contact.html') ||
    !str_contains($html, 'https://docs.example.com/download/steps.html')) {
    throw new RuntimeException('AI 生成时未完整保留源图片/链接或放行伪造网址');
}
foreach ([
    '<h2>缺图</h2>' . implode('', array_column($source['links'], 'marker')),
    '<h2>缺链接</h2>' . implode('', array_column($source['images'], 'marker')),
] as $invalid) {
    try { import_safe_html_output($invalid, $source); throw new LogicException('漏资源的生成结果被接受'); }
    catch (RuntimeException $e) { /* expected */ }
}
try { import_prepare_source('<p>请打开图片</p><img src="../assets/photo.jpg">'); throw new LogicException('缺少原网页 URL 被忽略'); }
catch (InvalidArgumentException $e) { /* expected */ }
try { import_prepare_source('<p>请阅读<a href="../help">帮助中心</a></p>'); throw new LogicException('缺少链接来源被忽略'); }
catch (InvalidArgumentException $e) { /* expected */ }

$many = import_prepare_source('<main><article><h2>第一部分</h2><p>设备准备工作</p></article><article><h2>第二部分</h2><p>账号绑定步骤</p></article></main>');
if (!str_contains($many['content'], '第一部分') || !str_contains($many['content'], '第二部分')) {
    throw new RuntimeException('多 article 的 HTML 只能提取第一段');
}
$protocolRelative = import_prepare_source('<h2>通用图片教程</h2><img src="//cdn.example.com/photo.png">');
if ($protocolRelative['images'][0]['src'] !== 'https://cdn.example.com/photo.png') {
    throw new RuntimeException('协议相对图片 URL 未正确处理');
}
$dynamic = import_prepare_source('<h2>平台说明</h2><p>正文说明</p><iframe src="https://example.com/embed"></iframe><canvas></canvas>');
if ($dynamic['unsupported_count'] !== 2 || !$dynamic['warnings']) throw new RuntimeException('动态元素缺失了警示');

$gif = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
$embedded = import_prepare_source('<p>这里是内嵌图片</p><img src="' . $gif . '">');
if ($embedded['images'][0]['src'] !== $gif) throw new RuntimeException('内嵌图片被错误清理');
$pdo = database();
$prepared = import_prepare_embedded($pdo, $embedded);
if ($prepared['embedded_count'] !== 1 || !str_starts_with($prepared['images'][0]['src'], '/uploads/')) {
    throw new RuntimeException('Base64 图片未写入素材库');
}
$stmt = $pdo->prepare('SELECT * FROM media_assets WHERE url=?');
$stmt->execute([$prepared['images'][0]['src']]);
$asset = $stmt->fetch();
if (!$asset || !is_file(media_file_path($asset))) throw new RuntimeException('内嵌图文件不存在');
media_delete($pdo, (int)$asset['id']);
echo "Universal HTML import passed: CMS/semantic region/srcset/picture/lazy/background/relative links/media integrity/inline images\n";
