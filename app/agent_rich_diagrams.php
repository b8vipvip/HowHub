<?php
declare(strict_types=1);

/**
 * Strict declarative Mermaid subset. Do not execute JS or include SVG.
 * Layers and an explicit edge list preserve branching topology.
 */
function agent_mermaid_graph_preview(string $definition): string
{
    if (strlen($definition)>7000 || !preg_match('//u',$definition)) return '';
    $parts=preg_split('/[;\r\n]+/u',trim($definition)) ?: [];
    $header=trim(array_shift($parts)??'');
    if (!preg_match('/^graph\s+(TD|TB|LR|RL)$/i',$header)) return '';
    $nodes=[];$edges=[];$seenEdges=[];
    $token='/^([A-Za-z][A-Za-z0-9_]{0,23})(?:\[([^\[\]{}()<>]{1,90})\]|\{([^\[\]{}()<>]{1,90})\}|\(([^\[\]{}()<>]{1,90})\))?$/u';
    foreach($parts as $line) {
        $line=trim($line);
        if ($line==='') continue;
        if (str_contains($line,':::') || str_contains($line,'click ') || str_contains($line,'&')) return '';
        $tokens=preg_split('/\s*-->\s*/u',$line);
        if (!is_array($tokens) || count($tokens)<2 || count($tokens)>6) return '';
        $ids=[];
        foreach($tokens as $term) {
            if (!preg_match($token,trim($term),$match)) return '';
            $id=$match[1];
            $label=trim((string)($match[2]??'') ?: (string)($match[3]??'') ?: (string)($match[4]??'') ?: $id);
            if ($label==='' || mb_strlen($label)>90) return '';
            $shape=($match[3]??'')!==''?'decision':'normal';
            if (!isset($nodes[$id])) $nodes[$id]=['label'=>$label,'shape'=>$shape];
            elseif ($label!==$id && $nodes[$id]['label']!==$label && $nodes[$id]['label']!==$id) return '';
            elseif ($label!==$id && $nodes[$id]['label']===$id) $nodes[$id]=['label'=>$label,'shape'=>$shape];
            $ids[]=$id;
        }
        for($j=1;$j<count($ids);$j++) {
            if($ids[$j-1]===$ids[$j]) return '';
            $key=$ids[$j-1].'|'.$ids[$j];
            if(!isset($seenEdges[$key])) {$edges[]=[$ids[$j-1],$ids[$j]];$seenEdges[$key]=true;}
        }
        if(count($nodes)>30 || count($edges)>40) return '';
    }
    if(count($nodes)<2 || !$edges) return '';
    $degree=array_fill_keys(array_keys($nodes),0);
    $next=[];
    foreach($edges as [$a,$b]) {$degree[$b]++;$next[$a][]=$b;}
    $queue=[];
    foreach($nodes as $id=>$unused) if($degree[$id]===0) $queue[]=$id;
    $levels=array_fill_keys(array_keys($nodes),0);
    $visited=0;
    while($queue) {
        $id=array_shift($queue);$visited++;
        foreach($next[$id]??[] as $to) {
            $levels[$to]=max($levels[$to],$levels[$id]+1);
            $degree[$to]--;
            if($degree[$to]===0) $queue[]=$to;
        }
    }
    if($visited!==count($nodes) || max($levels)>9) return '';
    $groups=[];
    foreach($nodes as $id=>$node) $groups[$levels[$id]][]=['id'=>$id]+$node;
    ksort($groups);
    $html='<figure class="hh-graph-preview"><figcaption>原创流程关系图（分层布局，非像素复刻）</figcaption><div class="hh-graph-layers">';
    foreach($groups as $level=>$items) {
        if($level>0) $html.='<div class="hh-graph-arrow" aria-hidden="true">↓</div>';
        $html.='<div class="hh-graph-level">';
        foreach($items as $node) {
            $html.='<div class="hh-graph-node'.($node['shape']==='decision'?' hh-graph-decision':'').'">'.
                h($node['label']).'</div>';
        }
        $html.='</div>';
    }
    $html.='</div><figcaption>连接关系：';
    $links=[];
    foreach($edges as [$a,$b]) $links[]=h($nodes[$a]['label']).' → '.h($nodes[$b]['label']);
    $html.=implode('；',$links).'</figcaption></figure>';
    return sanitize_content($html);
}

/** Bounded indented Mermaid mindmap without macros, external assets or JS. */
function agent_mermaid_mindmap_preview(string $definition): string
{
    if(strlen($definition)>7000 || !preg_match('//u',$definition)) return '';
    $lines=preg_split('/\r?\n/',trim($definition))?:[];
    if(strtolower(trim(array_shift($lines)??''))!=='mindmap') return '';
    $tree=[];$indentStack=[];$rootCount=0;
    foreach($lines as $line) {
        if(trim($line)==='') continue;
        if(!preg_match('/^( {1,16})(.{1,120})$/u',$line,$m)) return '';
        $indent=strlen($m[1]);$raw=trim($m[2]);
        if(preg_match('/[<>\x60]|:::|^(?:click|class|style|script|end)\b/i',$raw)) return '';
        if(preg_match('/^root\(\((.+)\)\)$/u',$raw,$root)) $raw=trim($root[1]);
        elseif(preg_match('/^\(\((.+)\)\)$/u',$raw,$root)) $raw=trim($root[1]);
        $label=trim($raw);
        if($label==='' || mb_strlen($label)>90) return '';
        while($indentStack && end($indentStack)>=$indent) array_pop($indentStack);
        $depth=count($indentStack);
        if($depth===0) $rootCount++;
        if($rootCount!==1 || $depth>5) return '';
        $tree[]=['depth'=>$depth,'label'=>$label];$indentStack[]=$indent;
        if(count($tree)>35) return '';
    }
    if(count($tree)<2) return '';
    $html='<figure class="hh-mind-preview"><figcaption>原创思维导图（可访问的树状结构）</figcaption><div class="hh-mind-tree">';
    $prior=-1;
    foreach($tree as $item) {
        $depth=$item['depth'];
        if($depth>$prior) $html.='<ul>';
        elseif($depth===$prior) $html.='</li>';
        else {
            for($k=$prior;$k>$depth;$k--) $html.='</li></ul>';
            $html.='</li>';
        }
        $html.='<li><span class="hh-mind-item">'.h($item['label']).'</span>';$prior=$depth;
    }
    for($k=$prior;$k>=0;$k--) $html.='</li></ul>';
    return sanitize_content($html.'</div></figure>');
}

function agent_mermaid_diagram_preview(string $definition): string
{
    // Preserve the established compact layout for simple linear chains.
    $linear=agent_mermaid_linear_preview($definition);
    if($linear!=='') return $linear;
    $graph=agent_mermaid_graph_preview($definition);
    if($graph!=='') return $graph;
    return agent_mermaid_mindmap_preview($definition);
}
