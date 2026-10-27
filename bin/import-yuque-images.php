<?php
// 可选：仅迁移内置首篇教程的语雀图片到本站，避免依赖第三方外链。
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = database();
$stmt = $pdo->prepare('SELECT id, body_html, cover_image FROM guides WHERE slug=?');
$stmt->execute(['tcl-leiniao-kugou-account']);
$guide = $stmt->fetch();
if (!$guide) { echo "首篇教程不存在。\n"; exit(1); }
$known = [
    'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958527127-397fcf90-56c3-4276-baa3-da960dc1ff85.png',
    'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958526750-64361ee2-1bdd-41b5-9d4a-068f5d643ce8.png',
    'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958528020-a7efb27a-a4a9-4430-99d7-3b6aeaf77502.png',
    'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958526446-13bf2dc7-6a21-4b1a-820e-2f81f5528610.png',
    'https://cdn.nlark.com/yuque/0/2025/png/27258103/1760958526780-a51ee332-7778-4f0a-9037-88cd840d6b58.png',
];
$directory = dirname(__DIR__) . '/public/uploads/yuque';
if (!is_dir($directory)) mkdir($directory, 0775, true);
$body = $guide['body_html'];
$cover = $guide['cover_image'];
foreach ($known as $i => $url) {
    if (!str_contains($body, $url) && $cover !== $url) continue;
    $file = 'tcl-kugou-' . ($i + 1) . '.png';
    $target = $directory . '/' . $file;
    if (!is_file($target)) {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'follow_location' => 0, 'user_agent' => 'HowHub importer'], 'https' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $handle = @fopen($url, 'rb', false, $ctx);
        if (!$handle) { echo "图片 {$file} 下载失败，保留外链。\n"; continue; }
        $data = stream_get_contents($handle, 7 * 1024 * 1024);
        fclose($handle);
        if ($data === false || strlen($data) > 6 * 1024 * 1024 || !str_starts_with($data, "\x89PNG\r\n\x1a\n")) { echo "图片 {$file} 格式/大小无效。\n"; continue; }
        if (file_put_contents($target, $data) === false) { echo "无法保存 {$file}。\n"; continue; }
    }
    $local = '/uploads/yuque/' . $file;
    $body = str_replace($url, $local, $body);
    if ($cover === $url) $cover = $local;
    echo "已就绪：{$local}\n";
}
$pdo->prepare('UPDATE guides SET body_html=?, cover_image=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$body, $cover, $guide['id']]);
echo "导入完成：成功迁移的图片已改为本站地址，其余保持原链接。\n";
