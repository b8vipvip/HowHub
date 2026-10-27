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
