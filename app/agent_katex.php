<?php
declare(strict_types=1);

/**
 * Optional offline KaTeX -> native MathML. This does not download CDN assets,
 * run authored JS or expose TeX as executable HTML. Unsupported TeX falls back
 * to the built-in limited parser and original editable <pre><code>.
 */
function agent_katex_node_bin(): string
{
    $standard=url_import_bin('node');
    if($standard!=='') return $standard;
    $configured=(string)(getenv('HOWHUB_NODE_BIN')?:'');
    if($configured==='' || !str_starts_with($configured,'/') ||
        strlen($configured)>255 || str_contains($configured,'..')) return '';
    $real=realpath($configured);
    return $real!==false && is_file($real) && is_executable($real)?$real:'';
}

function agent_katex_available(): bool
{
    return agent_katex_node_bin()!=='' &&
        is_file(HOWHUB_ROOT.'/node_modules/katex/dist/katex.js') &&
        is_file(HOWHUB_ROOT.'/bin/katex-mathml.cjs');
}

function agent_katex_mathml(string $tex): string
{
    if (setting('agent_katex_enabled','0')!=='1' || !agent_katex_available()) return '';
    if($tex==='' || strlen($tex)>1900 || !preg_match('//u',$tex)) return '';
    // Even with KaTeX trust=false, reject potentially side-effectful or
    // network-oriented command families before starting a child process.
    if (preg_match('/\\\\(?:href|url|includegraphics|htmlClass|htmlId|htmlStyle|htmlData|write|input|include|openout|read|catcode|def|gdef|newcommand|renewcommand)\b/i',$tex)) {
        return '';
    }
    $path=tempnam(sys_get_temp_dir(),'hh-tex-');
    if($path===false) return '';
    @chmod($path,0600);
    try {
        if(file_put_contents($path,$tex,LOCK_EX)!==strlen($tex)) return '';
        try {
            $output=url_import_process([agent_katex_node_bin(),
                HOWHUB_ROOT.'/bin/katex-mathml.cjs',$path],10,36000);
        } catch(Throwable $e) {return '';}
        if(!preg_match('~<math\b[^>]*>[\s\S]*?</math>~u',$output,$match)) return '';
        $math=$match[0];
        // Annotation contains a verbatim copy of the user's TeX and can
        // contain unsupported labels; never duplicate it into visible HTML.
        $math=preg_replace('~<annotation\b[^>]*>[\s\S]*?</annotation>~u','',$math)??'';
        if($math==='' || preg_match('~<(?:script|svg|iframe|img|a)\b~i',$math)) return '';
        $safe=sanitize_content($math);
        if(!str_contains($safe,'<math') || !str_contains($safe,'<mrow')) return '';
        if(strlen($safe)>30000) return '';
        return $safe;
    } finally {
        @unlink($path);
    }
}
