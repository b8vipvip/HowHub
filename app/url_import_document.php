<?php
declare(strict_types=1);

/** Store an imported file as a private downloadable attachment, not public executable content. */
function url_import_archive(PDO $pdo, string $bytes, string $ext, string $label): string
{
    $formats = ['pdf'=>'%PDF-','mp4'=>'ftyp','mov'=>'ftyp','webm'=>"\x1A\x45\xDF\xA3",
                'docx'=>"PK\x03\x04",'pptx'=>"PK\x03\x04",'xlsx'=>"PK\x03\x04"];
    if (!isset($formats[$ext]) || strlen($bytes) < 16 || strlen($bytes) > 30 * 1024 * 1024) {
        throw new RuntimeException('原文件大小或格式不允许转存');
    }
    $signature = $formats[$ext];
    $ok = in_array($ext, ['mp4','mov'], true) ? str_contains(substr($bytes, 0, 16), $signature) :
        str_starts_with($bytes, $signature);
    if (!$ok) throw new RuntimeException('原文件格式签名无效');
    $subdir = date('Y/m');
    $root = media_directory('file');
    $directory = $root . '/' . $subdir;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('文档素材目录不可写');
    }
    $relative = $subdir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $path = $root . '/' . $relative;
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        @unlink($path);
        throw new RuntimeException('原文件转存失败');
    }
    @chmod($path, 0600);
    try {
        $name = mb_substr($label, 0, 80) . '.' . $ext;
        $asset = media_register($pdo, 'file', $relative, $name, 'application/octet-stream', strlen($bytes));
        url_import_record_asset($asset);
        return $asset['url'];
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
}

/** Parse documents with installed tools only, never open them in a browser. */
function url_import_document_text(string $bytes, string $extension): string
{
    if (strlen($bytes) > 20 * 1024 * 1024) throw new RuntimeException('文档超过 20MB 限制');
    if ($extension === 'pdf') {
        if (!str_starts_with($bytes, '%PDF-')) throw new RuntimeException('PDF 文件头校验失败');
        $tool = url_import_bin('pdftotext');
        if ($tool === '') throw new RuntimeException('PDF 解析需要服务器安装 poppler-utils（pdftotext）');
    } elseif (in_array($extension, ['docx','pptx','xlsx'], true)) {
        if (!str_starts_with($bytes, "PK\x03\x04")) throw new RuntimeException('Office 文档格式校验失败');
        if (!class_exists(ZipArchive::class) && url_import_bin('unzip') === '') {
            throw new RuntimeException('Office 文档解析需要 PHP zip 扩展或 unzip 工具');
        }
    } else {
        throw new RuntimeException('尚不支持该文档格式的正文提取');
    }
    $root = sys_get_temp_dir() . '/hh-doc-' . bin2hex(random_bytes(12));
    if (!mkdir($root, 0700)) throw new RuntimeException('无法创建文档临时目录');
    $file = $root . '/document.' . $extension;
    $output = '';
    try {
        if (file_put_contents($file, $bytes) !== strlen($bytes)) throw new RuntimeException('文档临时存储失败');
        if ($extension === 'pdf') {
            $output = url_import_process([$tool,'-layout','-nopgbrk','-enc','UTF-8',$file,'-'], 25, 500000);
        } else {
            $entries = [];
            if ($extension === 'docx') $entries = ['word/document.xml'];
            elseif ($extension === 'xlsx') $entries = ['xl/sharedStrings.xml'];
            else for ($i=1; $i<=60; $i++) $entries[] = 'ppt/slides/slide' . $i . '.xml';
            $zip = class_exists(ZipArchive::class) ? new ZipArchive() : null;
            if ($zip !== null && $zip->open($file) !== true) throw new RuntimeException('无法读取 Office ZIP 内容');
            foreach ($entries as $entry) {
                if ($zip !== null) {
                    $raw = $zip->getFromName($entry, 500001);
                } else {
                    try { $raw = url_import_process([url_import_bin('unzip'),'-p',$file,$entry], 5, 500000); }
                    catch (Throwable $e) { continue; }
                }
                if (!is_string($raw) || $raw === '' || strlen($raw) > 500000) continue;
                // Word/PowerPoint/Excel XML text is data, not trusted markup.
                $raw = preg_replace('~</(?:w:p|a:p|row|si)>~', "\n", $raw) ?? $raw;
                $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($text !== '') $output .= "\n" . $text;
                if (strlen($output) > 300000) break;
            }
            if ($zip !== null) $zip->close();
        }
    } finally {
        @unlink($file);
        @rmdir($root);
    }
    $output = trim($output);
    if (mb_strlen($output) < 50) {
        throw new RuntimeException('文档未提取出足够文字；扫描 PDF 需要 OCR，图片型文档暂不能自动判断教程内容');
    }
    return mb_substr($output, 0, 80000);
}
