<?php
declare(strict_types=1);

/**
 * Origin attribution is mandatory for Agent-generated articles, but it is
 * NOT a license grant. Keep author's name, document URL, visible license,
 * modification note and access time distinct from private rights evidence.
 */
function agent_attribution_from_page(string $html, string $url, array $source = []): array
{
    $origin=agent_origin($url);
    $result=[
        'source_url'=>$url,
        'site_name'=>(string)parse_url($origin,PHP_URL_HOST),
        'author'=>'',
        'published'=>'',
        'license_url'=>'',
        'license_label'=>'',
        'mode'=>($source['rights_mode']??'summary')==='licensed'?'licensed':'summary',
        'accessed_at'=>gmdate('Y-m-d'),
        'modified'=>true,
    ];
    if (trim($html)==='') return $result;
    $doc=new DOMDocument('1.0','UTF-8');
    $previous=libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.substr($html,0,1048576).'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    foreach ($doc->getElementsByTagName('meta') as $meta) {
        $name=strtolower(trim($meta->getAttribute('name') ?: $meta->getAttribute('property')));
        $value=trim($meta->getAttribute('content'));
        if ($value==='') continue;
        if (in_array($name,['author','article:author','dc.creator','citation_author'],true) && $result['author']==='') {
            $result['author']=mb_substr($value,0,140);
        } elseif (in_array($name,['og:site_name','application-name'],true)) {
            $result['site_name']=mb_substr($value,0,140);
        } elseif (in_array($name,['article:published_time','date','dc.date','citation_date'],true) && $result['published']==='') {
            $result['published']=mb_substr($value,0,35);
        } elseif (in_array($name,['dc.rights','dc.license','copyright'],true) && $result['license_label']==='') {
            $result['license_label']=mb_substr($value,0,140);
        }
    }
    foreach ($doc->getElementsByTagName('link') as $link) {
        $rel=strtolower($link->getAttribute('rel'));
        if (!preg_match('/(?:^|\s)license(?:\s|$)/',$rel)) continue;
        $value=import_resolve_url($link->getAttribute('href'),$url);
        if ($value!=='') { $result['license_url']=$value;break; }
    }
    // A site can *claim* a license in metadata; retain that declaration but
    // never automatically switch source rights_mode from summary to licensed.
    return $result;
}

function agent_attribution_notice(array $meta): string
{
    $url=safe_link((string)($meta['source_url']??''));
    if ($url==='') throw new RuntimeException('Agent 文章需要一个有效原文 URL');
    $site=mb_substr(trim((string)($meta['site_name']??'')),0,140);
    $author=mb_substr(trim((string)($meta['author']??'')),0,140);
    $published=mb_substr(trim((string)($meta['published']??'')),0,35);
    $license=mb_substr(trim((string)($meta['license_label']??'')),0,140);
    $licenseUrl=safe_link((string)($meta['license_url']??''));
    $mode=($meta['mode']??'summary')==='licensed'?'licensed':'summary';
    $html='<blockquote class="hh-source-attribution"><p><strong>资料来源：</strong>'.
        '<a href="'.h($url).'">'.h($site!==''?$site:$url).'</a>'.
        '（<a href="'.h($url).'">查看原文</a>）</p>';
    if ($author!=='') $html.='<p><strong>原作者：</strong>'.h($author).'</p>';
    if ($published!=='') $html.='<p><strong>原文日期：</strong>'.h($published).'</p>';
    if ($license!=='' || $licenseUrl!=='') {
        $html.='<p><strong>原站声明的许可：</strong>'.h($license!==''?$license:'查看许可详情');
        if ($licenseUrl!=='') $html.='（<a href="'.h($licenseUrl).'">查看许可文本</a>）';
        $html.='。该声明仍须核实适用范围。</p>';
    }
    $html.='<p><strong>内容处理：</strong>'.($mode==='licensed'
        ? '已由管理员登记使用授权，含经许可的改写或结构整理；具体适用范围仍需核实。'
        : '基于来源信息生成的原创摘要，并非整篇原文转载；不复制未获许可的媒体。').'</p>';
    $html.='<p><strong>核验说明：</strong>由 AI 辅助整理，尚未独立核验所有事实；原作者及权利人保留其合法权利。'.
        '注明出处不等于取得转载许可。</p></blockquote>';
    return sanitize_content($html);
}

/** Snapshot the notice when the candidate is approved, so later article edits keep it. */
function agent_preserve_source_notice(PDO $pdo,int $guideId,string $html): string
{
    if ($guideId<1) return $html;
    $q=$pdo->prepare("SELECT format_notes FROM agent_candidates WHERE guide_id=? LIMIT 1");
    $q->execute([$guideId]);
    $json=$q->fetchColumn();
    if (!is_string($json)) return $html;
    $notes=json_decode($json,true);
    $meta=is_array($notes) ? ($notes['attribution']??null) : null;
    if (!is_array($meta) || empty($meta['source_url'])) {
        throw new RuntimeException('无法找到 Agent 内容的原始出处记录，已拒绝覆盖文章');
    }
    // Strip the current notice (even when malformed) and recreate exactly
    // one canonical copy at the start. No user-created HTML can suppress it.
    $doc=new DOMDocument('1.0','UTF-8');
    $previous=libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="agent-body-root">'.$html.'</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    $root=$doc->getElementById('agent-body-root');
    if ($root===null) throw new RuntimeException('文章内容解析失败');
    $xp=new DOMXPath($doc);
    foreach ($xp->query('.//blockquote[contains(concat(" ",normalize-space(@class)," ")," hh-source-attribution ")]',$root) ?: [] as $block) {
        $block->parentNode?->removeChild($block);
    }
    $rest='';
    foreach ($root->childNodes as $node) $rest.=$doc->saveHTML($node);
    $notice=agent_attribution_notice($meta);
    return sanitize_content($notice.$rest);
}
