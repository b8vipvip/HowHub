<?php
declare(strict_types=1);

/** Extract articles without fetching a site, executing scripts, or losing resources. */
function import_extract_html(string $raw, string $pageUrl = ''): array
{
    if (strlen($raw) > 300000) throw new InvalidArgumentException('网页代码不得超过 300KB');
    if (trim($raw) === '') throw new InvalidArgumentException('请粘贴网页代码或图文内容');
    $base = import_page_url($pageUrl);
    $raw = preg_replace('/\\\\([<>])/', '$1', $raw) ?? $raw;
    $raw = str_replace(['https\\://','http\\://','\\/'], ['https://','http://','/'], $raw);
    if (!str_contains($raw, '<')) $raw = '<p>' . htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $raw . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    if (!$loaded) throw new InvalidArgumentException('无法解析网页 HTML');
    $state = [
        'base'=>$base, 'output'=>'', 'images'=>[], 'links'=>[],
        'missing_images'=>0, 'missing_links'=>0, 'unsupported'=>[],
    ];
    $body = import_select_body($doc);
    foreach ($body->childNodes as $child) import_walk($child, $state);
    if ($state['missing_images'] > 0) {
        throw new InvalidArgumentException('原网页有 ' . $state['missing_images'] .
            ' 张图片缺少可解析的 src / srcset / data-* 地址（可能尚未加载或为相对地址）。请在源网页加载全部图片并填写原网页网址；未调用 AI，编辑器不变。');
    }
    if ($state['missing_links'] > 0) {
        throw new InvalidArgumentException('原网页有 ' . $state['missing_links'] .
            ' 个相对链接无法解析，请填写原网页网址后再导入，避免链接丢失。');
    }
    $output = trim(preg_replace('/[ \t]+/u', ' ',
        preg_replace('/\n{3,}/', "\n\n", $state['output']) ?? '') ?? '');
    if (mb_strlen($output) < 4) throw new InvalidArgumentException('网页内容不足，无法识别教程');
    if (mb_strlen($output) > 90000) {
        throw new InvalidArgumentException('提取的正文超过 90000 字符，请分段导入；内容不会被静默截断');
    }
    $warnings = [];
    if ($state['unsupported']) {
        $parts = [];
        foreach ($state['unsupported'] as $tag=>$count) $parts[] = $tag . '×' . $count;
        $warnings[] = '静态 HTML 无法恢复的嵌入或动态内容：' . implode('、', $parts);
    }
    return [
        'content'=>$output, 'images'=>$state['images'], 'links'=>$state['links'],
        'link_count'=>count($state['links']), 'unsupported_count'=>array_sum($state['unsupported']),
        'warnings'=>$warnings,
    ];
}
