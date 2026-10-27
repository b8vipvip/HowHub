<?php
declare(strict_types=1);

/** Media assets are owned by the local installation, never by an external CDN. */
function media_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS media_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL CHECK(kind IN ('image', 'file')),
    url TEXT NOT NULL UNIQUE,
    disk_path TEXT NOT NULL,
    original_name TEXT NOT NULL,
    mime TEXT NOT NULL,
    byte_size INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS media_kind_date ON media_assets(kind, id DESC);
SQL);
}

function media_directory(string $kind): string
{
    return $kind === 'image' ? HOWHUB_ROOT . '/public/uploads' : HOWHUB_ROOT . '/storage/media_files';
}

function media_file_path(array $asset): ?string
{
    if (!in_array($asset['kind'], ['image', 'file'], true)) return null;
    $root = realpath(media_directory($asset['kind']));
    if ($root === false) return null;
    $relative = $asset['disk_path'];
    if (!is_string($relative) || str_contains($relative, '..') || str_contains($relative, '\\') || str_starts_with($relative, '/')) return null;
    $full = realpath($root . '/' . $relative);
    if ($full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) return null;
    return $full;
}

function media_register(PDO $pdo, string $kind, string $relative, string $name, string $mime, int $size): array
{
    $url = $kind === 'image' ? '/uploads/' . $relative : '';
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO media_assets (kind, url, disk_path, original_name, mime, byte_size) VALUES (?, ?, ?, ?, ?, ?)');
    if ($kind === 'file') {
        // URL holds the final route, assigned after the generated id exists.
        $stmt = $pdo->prepare('INSERT INTO media_assets (kind, url, disk_path, original_name, mime, byte_size) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$kind, '/file/pending-' . bin2hex(random_bytes(12)), $relative, $name, $mime, $size]);
        $id = (int)$pdo->lastInsertId();
        $url = '/file/' . $id;
        $pdo->prepare('UPDATE media_assets SET url=? WHERE id=?')->execute([$url, $id]);
    } else {
        $stmt->execute([$kind, $url, $relative, $name, $mime, $size]);
    }
    $q = $pdo->prepare('SELECT * FROM media_assets WHERE url=?');
    $q->execute([$url]);
    return $q->fetch();
}

function media_upload(PDO $pdo, array $file): array
{
    $size = (int)($file['size'] ?? 0);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $size < 1 || $size > 20 * 1024 * 1024) {
        throw new RuntimeException('请选择不超过 20MB 的图片或文档');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('上传文件无效');
    $name = trim(basename(str_replace('\\', '/', (string)($file['name'] ?? '文件'))));
    $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $name), 0, 120);
    if ($name === '') $name = '未命名文件';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $images = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $docs = ['pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];
    $kind = 'file';
    if (isset($images[$ext]) && $mime === $images[$ext] && @getimagesize($tmp)) {
        if ($size > 6 * 1024 * 1024) throw new RuntimeException('图片大小不能超过 6MB');
        $kind = 'image';
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
    } elseif (in_array($ext, $docs, true)) {
        $prefix = file_get_contents($tmp, false, null, 0, 16) ?: '';
        $zip = str_starts_with($prefix, "PK\x03\x04") || str_starts_with($prefix, "PK\x05\x06");
        $ole = str_starts_with($prefix, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        $valid = match ($ext) {
            'pdf' => str_starts_with($prefix, '%PDF-') && $mime === 'application/pdf',
            'txt' => $mime === 'text/plain' && !str_contains($prefix, "\x00"),
            'doc', 'xls', 'ppt' => $ole,
            'docx', 'xlsx', 'pptx', 'zip' => $zip,
            default => false,
        };
        if (!$valid) throw new RuntimeException('文档类型与实际内容不匹配');
        // Media documents are always delivered as forced downloads.
        $mime = 'application/octet-stream';
    } else {
        throw new RuntimeException('仅支持 PNG/JPG/WebP/GIF 图片和 PDF/TXT/Word/Excel/PPT/ZIP 文档');
    }
    $subdir = date('Y/m');
    $root = media_directory($kind);
    $directory = $root . '/' . $subdir;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('素材目录不可写');
    $relative = $subdir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $root . '/' . $relative;
    if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('保存素材失败，请检查权限');
    @chmod($dest, 0644);
    try {
        return media_register($pdo, $kind, $relative, $name, $mime, $size);
    } catch (Throwable $e) {
        @unlink($dest);
        throw $e;
    }
}

/** Discover images uploaded by older HowHub versions without modifying their URLs. */
function media_sync_legacy(PDO $pdo): void
{
    $root = realpath(media_directory('image'));
    if ($root === false) return;
    $query = $pdo->prepare('SELECT id FROM media_assets WHERE url=? LIMIT 1');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) continue;
        $file = $item->getPathname();
        $relative = substr($file, strlen($root) + 1);
        if (!preg_match('~^[A-Za-z0-9_/-]+\.(png|jpe?g|webp|gif)$~i', $relative)) continue;
        $url = '/uploads/' . $relative;
        $query->execute([$url]);
        if ($query->fetchColumn()) continue;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
        if (!in_array($mime, ['image/png','image/jpeg','image/webp','image/gif'], true) || !@getimagesize($file)) continue;
        media_register($pdo, 'image', $relative, basename($relative), $mime, filesize($file));
    }
}

function media_usage(PDO $pdo, string $url): int
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM guides WHERE cover_image=? OR instr(body_html, ?) > 0');
    $q->execute([$url, '="' . $url . '"']);
    return (int)$q->fetchColumn();
}

function media_delete(PDO $pdo, int $id): void
{
    $q = $pdo->prepare('SELECT * FROM media_assets WHERE id=?');
    $q->execute([$id]);
    $asset = $q->fetch();
    if (!$asset) throw new RuntimeException('素材不存在');
    if (media_usage($pdo, $asset['url']) > 0) throw new RuntimeException('此素材仍被教程引用，请先在文章中替换或移除，再删除文件');
    $path = media_file_path($asset);
    $pdo->prepare('DELETE FROM media_assets WHERE id=?')->execute([$id]);
    if ($path !== null) @unlink($path);
}

function media_usage_published(PDO $pdo, string $url): int
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM guides WHERE status='published' AND instr(body_html, ?) > 0");
    $q->execute(['="' . $url . '"']);
    return (int)$q->fetchColumn();
}
