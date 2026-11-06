<?php
declare(strict_types=1);

/**
 * Tiny deliberately limited TeX -> presentation MathML converter.
 * Unsupported macros remain visible as editable source; never run arbitrary
 * LaTeX packages, JavaScript, SVG or remote math renderers.
 */
function agent_math_atom(string $s, int &$i, int $depth): string
{
    if ($depth>12 || $i>=strlen($s)) throw new RuntimeException('公式嵌套过深或缺少表达式');
    $c=$s[$i];
    if ($c==='{') {
        $i++;
        $children=agent_math_sequence($s,$i,$depth+1,true);
        if ($i>=strlen($s) || $s[$i]!=='}') throw new RuntimeException('公式大括号不匹配');
        $i++;
        return '<mrow>'.$children.'</mrow>';
    }
    if ($c==='\\') {
        $i++;
        if (!preg_match('/\G[a-zA-Z]+/A',substr($s,$i),$match)) throw new RuntimeException('不支持的 TeX 控制符');
        $macro=$match[0];$i+=strlen($macro);
        if ($macro==='frac') {
            $up=agent_math_atom($s,$i,$depth+1);
            $down=agent_math_atom($s,$i,$depth+1);
            return '<mfrac>'.$up.$down.'</mfrac>';
        }
        if ($macro==='sqrt') return '<msqrt>'.agent_math_atom($s,$i,$depth+1).'</msqrt>';
        if (in_array($macro,['text','mathrm','operatorname'],true)) {
            if (($s[$i]??'')!=='{') throw new RuntimeException('数学文本宏缺少大括号');
            $end=strpos($s,'}',$i+1);
            if ($end===false || $end-$i>180) throw new RuntimeException('数学文本宏长度异常');
            $value=substr($s,$i+1,$end-$i-1);
            if ($value==='' || strpbrk($value,'\\{}')!==false) throw new RuntimeException('数学文本不允许嵌套指令');
            $i=$end+1;
            return '<mtext>'.h($value).'</mtext>';
        }
        $names=['alpha'=>'α','beta'=>'β','gamma'=>'γ','delta'=>'δ','theta'=>'θ',
            'lambda'=>'λ','mu'=>'μ','pi'=>'π','sigma'=>'σ','omega'=>'ω','phi'=>'φ'];
        $operators=['times'=>'×','cdot'=>'·','leq'=>'≤','geq'=>'≥','neq'=>'≠',
            'pm'=>'±','infty'=>'∞','to'=>'→','sum'=>'∑','prod'=>'∏','int'=>'∫',
            'lim'=>'lim','partial'=>'∂','nabla'=>'∇','approx'=>'≈','equiv'=>'≡',
            'subseteq'=>'⊆','supseteq'=>'⊇','in'=>'∈','forall'=>'∀','exists'=>'∃',
            'rightarrow'=>'→','leftarrow'=>'←','leftrightarrow'=>'↔','cdots'=>'⋯'];
        if (isset($names[$macro])) return '<mi>'.h($names[$macro]).'</mi>';
        if (isset($operators[$macro])) return '<mo>'.h($operators[$macro]).'</mo>';
        throw new RuntimeException('暂不支持的 TeX 公式指令');
    }
    if (preg_match('/\G[0-9]+(?:\.[0-9]+)?/A',substr($s,$i),$m)) {
        $i+=strlen($m[0]);
        return '<mn>'.h($m[0]).'</mn>';
    }
    if (preg_match('/\G[a-zA-Z]+/A',substr($s,$i),$m)) {
        $i+=strlen($m[0]);return '<mi>'.h($m[0]).'</mi>';
    }
    if (str_contains('+-=*/()[]<>|,!:',$c)) {
        $i++;return '<mo>'.h($c).'</mo>';
    }
    throw new RuntimeException('公式包含未支持的字符');
}

function agent_math_sequence(string $s,int &$i,int $depth=0,bool $group=false): string
{
    if ($depth>12) throw new RuntimeException('公式嵌套过深');
    $output=[];
    while ($i<strlen($s)) {
        if ($s[$i]==='}') {
            if (!$group) throw new RuntimeException('公式出现多余的右大括号');
            break;
        }
        if (ctype_space($s[$i])) {$i++;continue;}
        if (count($output)>150) throw new RuntimeException('公式表达式过长');
        if (str_contains('^_',$s[$i])) throw new RuntimeException('公式上下标缺少基底');
        $base=agent_math_atom($s,$i,$depth);
        $sub=null;$sup=null;
        while ($i<strlen($s) && str_contains('^_',$s[$i])) {
            $type=$s[$i++];
            if ($type==='^') {
                if ($sup!==null) throw new RuntimeException('上下标重复');
                $sup=agent_math_atom($s,$i,$depth+1);
            } else {
                if ($sub!==null) throw new RuntimeException('上下标重复');
                $sub=agent_math_atom($s,$i,$depth+1);
            }
        }
        if ($sub!==null && $sup!==null) $base='<msubsup>'.$base.$sub.$sup.'</msubsup>';
        elseif ($sub!==null) $base='<msub>'.$base.$sub.'</msub>';
        elseif ($sup!==null) $base='<msup>'.$base.$sup.'</msup>';
        $output[]=$base;
    }
    return implode('',$output);
}

/** A restricted TeX matrix form, max 8x8, rendered as browser-native MathML. */
function agent_tex_matrix_mathml(string $tex): string
{
    if (!preg_match('/^\\\\begin\{(matrix|pmatrix|bmatrix|vmatrix)\}([\s\S]{1,1800})\\\\end\{\1\}$/u',$tex,$m)) return '';
    $rows=explode('\\\\',trim($m[2]));
    if(count($rows)<1 || count($rows)>8) return '';
    $content='<mtable>';$cols=null;
    foreach($rows as $row) {
        $cells=explode('&',$row);
        if(count($cells)<1 || count($cells)>8 || ($cols!==null && $cols!==count($cells))) return '';
        $cols=count($cells);
        $content.='<mtr>';
        foreach($cells as $cell) {
            $cell=trim($cell);
            if($cell==='') return '';
            try {
                $i=0;
                $part=agent_math_sequence($cell,$i);
                if($i!==strlen($cell) || $part==='') return '';
            } catch(Throwable $e) {return '';}
            $content.='<mtd><mrow>'.$part.'</mrow></mtd>';
        }
        $content.='</mtr>';
    }
    $content.='</mtable>';
    $brackets=match($m[1]) {
        'pmatrix'=>['(',')'],'bmatrix'=>['[',']'],'vmatrix'=>['|','|'],
        default=>['',''],
    };
    return sanitize_content('<math><mrow>'.($brackets[0]!==''?'<mo>'.h($brackets[0]).'</mo>':'').
        $content.($brackets[1]!==''?'<mo>'.h($brackets[1]).'</mo>':'').'</mrow></math>');
}

function agent_tex_mathml(string $tex): string
{
    $tex=trim($tex);
    if ($tex==='' || strlen($tex)>1900 || !preg_match('//u',$tex)) return '';
    if (str_starts_with($tex,'$$') && str_ends_with($tex,'$$')) $tex=substr($tex,2,-2);
    elseif (str_starts_with($tex,'$') && str_ends_with($tex,'$')) $tex=substr($tex,1,-1);
    $matrix=agent_tex_matrix_mathml($tex);
    if ($matrix!=='') return $matrix;
    // Expand only fixed paired delimiter markers, never arbitrary macros.
    $tex=str_replace(['\left(','\right)','\left[','\right]','\left|','\right|'],
        ['(',')','[',']','|','|'],$tex);
    try {
        $i=0;
        $content=agent_math_sequence($tex,$i);
        if ($i!==strlen($tex) || $content==='') return '';
        return sanitize_content('<math><mrow>'.$content.'</mrow></math>');
    } catch (Throwable $e) { return ''; }
}

/** Render a *linear* Mermaid chain, not arbitrary graphs or mind maps. */
function agent_mermaid_linear_preview(string $definition): string
{
    if (strlen($definition)>3000 || !preg_match('/^\s*graph\s+(?:TD|TB|LR|RL)\s*(?:;|\r?\n|$)/i',$definition)) return '';
    // A branching graph is too complex for a truthful inline approximation.
    $edges=[];
    $labels=[];
    preg_match_all('/([A-Za-z][A-Za-z0-9_]*)(?:\[([^\[\]\r\n]{1,60})\])?\s*-->\s*([A-Za-z][A-Za-z0-9_]*)(?:\[([^\[\]\r\n]{1,60})\])?/u',$definition,$matches,PREG_SET_ORDER);
    foreach($matches as $m) {
        $from=$m[1];$to=$m[3];
        if ($from===$to || isset($edges[$from])) return '';
        $edges[$from]=$to;
        if (!empty($m[2])) $labels[$from]=$m[2];
        if (!empty($m[4])) $labels[$to]=$m[4];
        if (count($edges)>8) return '';
    }
    if (!$edges) return '';
    // Never show a partial flow for Mermaid with styles, decision branches,
    // labelled edges, subgraphs or any grammar this small renderer cannot parse.
    $remainder=preg_replace('/^\s*graph\s+(?:TD|TB|LR|RL)\s*(?:;|\r?\n|$)/i','',$definition);
    $remainder=preg_replace('/[A-Za-z][A-Za-z0-9_]*(?:\[[^\[\]\r\n]{1,60}\])?\s*-->\s*[A-Za-z][A-Za-z0-9_]*(?:\[[^\[\]\r\n]{1,60}\])?/u','',$remainder ?? '');
    $remainder=preg_replace('/[;\s]+/u','',$remainder ?? '');
    if ($remainder!=='') return '';
    $incoming=array_fill_keys(array_keys($edges),0);
    foreach($edges as $to) $incoming[$to]=($incoming[$to]??0)+1;
    foreach($incoming as $count) if ($count>1) return '';
    $starts=array_keys(array_filter($incoming,static fn($v)=>$v===0));
    if (count($starts)!==1) return '';
    $chain=[];
    $cur=$starts[0];$seen=[];
    while ($cur!==null && !isset($seen[$cur])) {
        $seen[$cur]=true;$chain[]=$labels[$cur]??$cur;
        $cur=$edges[$cur]??null;
    }
    if ($cur!==null || count($seen)!==count($incoming)) return '';
    $html='<figure class="hh-flow-preview"><figcaption>简化流程示意（非原图 1:1）</figcaption><div class="hh-flow-row">';
    foreach($chain as $n=>$label) {
        if ($n>0) $html.='<span class="hh-flow-arrow">→</span>';
        $html.='<span class="hh-flow-node">'.h(mb_substr(trim($label),0,60)).'</span>';
    }
    return sanitize_content($html.'</div></figure>');
}
) && str_ends_with($tex,'
        $i=0;
        $content=agent_math_sequence($tex,$i);
        if ($i!==strlen($tex) || $content==='') return '';
        return sanitize_content('<math><mrow>'.$content.'</mrow></math>');
    } catch (Throwable $e) { return ''; }
}

/** Render a *linear* Mermaid chain, not arbitrary graphs or mind maps. */
function agent_mermaid_linear_preview(string $definition): string
{
    if (strlen($definition)>3000 || !preg_match('/^\s*graph\s+(?:TD|TB|LR|RL)\s*(?:;|\r?\n|$)/i',$definition)) return '';
    // A branching graph is too complex for a truthful inline approximation.
    $edges=[];
    $labels=[];
    preg_match_all('/([A-Za-z][A-Za-z0-9_]*)(?:\[([^\[\]\r\n]{1,60})\])?\s*-->\s*([A-Za-z][A-Za-z0-9_]*)(?:\[([^\[\]\r\n]{1,60})\])?/u',$definition,$matches,PREG_SET_ORDER);
    foreach($matches as $m) {
        $from=$m[1];$to=$m[3];
        if ($from===$to || isset($edges[$from])) return '';
        $edges[$from]=$to;
        if (!empty($m[2])) $labels[$from]=$m[2];
        if (!empty($m[4])) $labels[$to]=$m[4];
        if (count($edges)>8) return '';
    }
    if (!$edges) return '';
    // Never show a partial flow for Mermaid with styles, decision branches,
    // labelled edges, subgraphs or any grammar this small renderer cannot parse.
    $remainder=preg_replace('/^\s*graph\s+(?:TD|TB|LR|RL)\s*(?:;|\r?\n|$)/i','',$definition);
    $remainder=preg_replace('/[A-Za-z][A-Za-z0-9_]*(?:\[[^\[\]\r\n]{1,60}\])?\s*-->\s*[A-Za-z][A-Za-z0-9_]*(?:\[[^\[\]\r\n]{1,60}\])?/u','',$remainder ?? '');
    $remainder=preg_replace('/[;\s]+/u','',$remainder ?? '');
    if ($remainder!=='') return '';
    $incoming=array_fill_keys(array_keys($edges),0);
    foreach($edges as $to) $incoming[$to]=($incoming[$to]??0)+1;
    foreach($incoming as $count) if ($count>1) return '';
    $starts=array_keys(array_filter($incoming,static fn($v)=>$v===0));
    if (count($starts)!==1) return '';
    $chain=[];
    $cur=$starts[0];$seen=[];
    while ($cur!==null && !isset($seen[$cur])) {
        $seen[$cur]=true;$chain[]=$labels[$cur]??$cur;
        $cur=$edges[$cur]??null;
    }
    if ($cur!==null || count($seen)!==count($incoming)) return '';
    $html='<figure class="hh-flow-preview"><figcaption>简化流程示意（非原图 1:1）</figcaption><div class="hh-flow-row">';
    foreach($chain as $n=>$label) {
        if ($n>0) $html.='<span class="hh-flow-arrow">→</span>';
        $html.='<span class="hh-flow-node">'.h(mb_substr(trim($label),0,60)).'</span>';
    }
    return sanitize_content($html.'</div></figure>');
}
)) $tex=substr($tex,1,-1);
    $matrix=agent_tex_matrix_mathml($tex);
    if ($matrix!=='') return $matrix;
    // Support only fixed delimiter macros; arbitrary TeX expansion stays disabled.
    $tex=str_replace(['\\left(','\\right)','\\left[','\\right]','\\left|','\\right|'],
        ['(',')','[',']','|','|'],$tex);
    try {
        $i=0;
        $content=agent_math_sequence($tex,$i);
        if ($i!==strlen($tex) || $content==='') return '';
        return sanitize_content('<math><mrow>'.$content.'</mrow></math>');
    } catch (Throwable $e) { return ''; }
}

/** Render a *linear* Mermaid chain, not arbitrary graphs or mind maps. */
function agent_mermaid_linear_preview(string $definition): string
{
    if (strlen($definition)>3000 || !preg_match('/^\s*graph\s+(?:TD|TB|LR|RL)\s*(?:;|\r?\n|$)/i',$definition)) return '';
    // A branching graph is too complex for a truthful inline approximation.
    $edges=[];
    $labels=[];
    preg_match_all('/([A-Za-z][A-Za-z0-9_]*)(?:\[([^\[\]\r\n]{1,60})\])?\s*-->\s*([A-Za-z][A-Za-z0-9_]*)(?:\[([^\[\]\r\n]{1,60})\])?/u',$definition,$matches,PREG_SET_ORDER);
    foreach($matches as $m) {
        $from=$m[1];$to=$m[3];
        if ($from===$to || isset($edges[$from])) return '';
        $edges[$from]=$to;
        if (!empty($m[2])) $labels[$from]=$m[2];
        if (!empty($m[4])) $labels[$to]=$m[4];
        if (count($edges)>8) return '';
    }
    if (!$edges) return '';
    // Never show a partial flow for Mermaid with styles, decision branches,
    // labelled edges, subgraphs or any grammar this small renderer cannot parse.
    $remainder=preg_replace('/^\s*graph\s+(?:TD|TB|LR|RL)\s*(?:;|\r?\n|$)/i','',$definition);
    $remainder=preg_replace('/[A-Za-z][A-Za-z0-9_]*(?:\[[^\[\]\r\n]{1,60}\])?\s*-->\s*[A-Za-z][A-Za-z0-9_]*(?:\[[^\[\]\r\n]{1,60}\])?/u','',$remainder ?? '');
    $remainder=preg_replace('/[;\s]+/u','',$remainder ?? '');
    if ($remainder!=='') return '';
    $incoming=array_fill_keys(array_keys($edges),0);
    foreach($edges as $to) $incoming[$to]=($incoming[$to]??0)+1;
    foreach($incoming as $count) if ($count>1) return '';
    $starts=array_keys(array_filter($incoming,static fn($v)=>$v===0));
    if (count($starts)!==1) return '';
    $chain=[];
    $cur=$starts[0];$seen=[];
    while ($cur!==null && !isset($seen[$cur])) {
        $seen[$cur]=true;$chain[]=$labels[$cur]??$cur;
        $cur=$edges[$cur]??null;
    }
    if ($cur!==null || count($seen)!==count($incoming)) return '';
    $html='<figure class="hh-flow-preview"><figcaption>简化流程示意（非原图 1:1）</figcaption><div class="hh-flow-row">';
    foreach($chain as $n=>$label) {
        if ($n>0) $html.='<span class="hh-flow-arrow">→</span>';
        $html.='<span class="hh-flow-node">'.h(mb_substr(trim($label),0,60)).'</span>';
    }
    return sanitize_content($html.'</div></figure>');
}
