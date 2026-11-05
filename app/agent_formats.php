<?php
declare(strict_types=1);

/**
 * Safe, structured source extraction.  Tables remain editable HTML; formulas
 * and diagram definitions remain legible source (never run remote JS/SVG).
 * Full structure blocks are reused only on explicitly licensed sources.
 */
function agent_source_features(string $html): array
{
    if (strlen($html)>1048576) throw new RuntimeException('页面结构数据超过 1MB');
    $doc = new DOMDocument('1.0','UTF-8');
    $prev = libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    } finally { libxml_clear_errors(); libxml_use_internal_errors($prev); }
    $node = import_select_body($doc);
    $xp = new DOMXPath($doc);
    $tables = [];
    $formulas = [];
    $diagrams = [];
    $warnings = [];
    foreach ($xp->query('.//table',$node) ?: [] as $table) {
        if (count($tables)>=8) break;
        $content = (string)$doc->saveHTML($table);
        if (strlen($content)>10000) { $warnings[]='存在超大表格，需人工检查';continue; }
        $clean = sanitize_content($content);
        if (substr_count($clean,'<tr')>=1) $tables[] = $clean;
    }
    foreach ($xp->query('.//math | .//*[@data-tex] | .//script[@type="math/tex"] | .//annotation[@encoding="application/x-tex"]',$node) ?: [] as $math) {
        if (count($formulas)>=25) break;
        if (!$math instanceof DOMElement) continue;
        $tex = trim($math->getAttribute('data-tex'));
        if ($tex === '') {
            foreach ($xp->query('.//*[local-name()="annotation"]',$math) ?: [] as $annotation) {
                if ($annotation instanceof DOMElement && str_contains(strtolower($annotation->getAttribute('encoding')),'tex')) {
                    $tex = trim($annotation->textContent); break;
                }
            }
        }
        if ($tex === '') $tex = trim($math->textContent);
        if ($tex !== '') $formulas[] = mb_substr($tex,0,1000);
    }
    foreach ($xp->query('.//pre | .//code',$node) ?: [] as $code) {
        if (count($diagrams)>=12) break;
        if (!$code instanceof DOMElement) continue;
        $class = strtolower($code->getAttribute('class'));
        if (!preg_match('/mermaid|mindmap|graphviz|plantuml|flowchart/',$class)) continue;
        $definition = trim($code->textContent);
        if ($definition !== '') $diagrams[] = mb_substr($definition,0,5000);
    }
    $vectorCount = $xp->query('.//svg | .//canvas',$node)?->length ?? 0;
    if ($vectorCount) $warnings[]='含 '.$vectorCount.' 个 SVG/Canvas 绘制元素，静态解析不能保证原图还原';
    if ($diagrams) $warnings[]='导图/流程图定义已保留为可编辑源码；图形渲染仍需专用渲染器';
    if ($formulas) $warnings[]='公式已保留原始表达式；当前使用代码排版，不宣称数学排版 1:1';
    return [
        'tables'=>$tables,'formulas'=>$formulas,'diagrams'=>$diagrams,
        'counts'=>['tables'=>count($tables),'formulas'=>count($formulas),
            'diagrams'=>count($diagrams),'vector'=>$vectorCount],
        'warnings'=>$warnings,
    ];
}

function agent_structured_html(array $features): string
{
    $html = '';
    if ($features['tables']) {
        $html .= '<h2>原文数据表</h2>';
        foreach ($features['tables'] as $table) $html .= $table;
    }
    if ($features['formulas']) {
        $html .= '<h2>原文公式</h2>';
        foreach ($features['formulas'] as $tex) {
            $html .= '<pre><code>'.h($tex).'</code></pre>';
        }
    }
    if ($features['diagrams']) {
        $html .= '<h2>流程图和导图定义</h2>';
        foreach ($features['diagrams'] as $definition) {
            $html .= '<pre><code>'.h($definition).'</code></pre>';
        }
    }
    return sanitize_content($html);
}

function agent_article_excerpt(string $html): string
{
    $doc = new DOMDocument('1.0','UTF-8');
    $prev=libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.substr($html,0,1048576).'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    } finally { libxml_clear_errors();libxml_use_internal_errors($prev); }
    $root=import_select_body($doc);
    $xp=new DOMXPath($doc);
    foreach ($xp->query('.//script | .//style | .//nav | .//footer | .//form | .//aside',$root) ?: [] as $child) {
        $child->parentNode?->removeChild($child);
    }
    $text=trim(preg_replace('/[ \t]+/u',' ',preg_replace('/\n{3,}/',"\n\n",
        $root->textContent) ?? '') ?? '');
    if (mb_strlen($text)<100) throw new RuntimeException('网页正文信息不足，不能进行可靠的知识评估');
    return mb_substr($text,0,16000);
}
