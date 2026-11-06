<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$old=setting('agent_katex_enabled','0');
try {
    set_setting('agent_katex_enabled','0');
    if(agent_katex_mathml('\\binom{n}{k}')!=='')
        throw new RuntimeException('Disabled advanced renderer unexpectedly started');
    if(!str_contains(agent_tex_mathml('\\frac{1}{2}'),'<mfrac>'))
        throw new RuntimeException('Built-in fallback MathML lost basic fractions');
    set_setting('agent_katex_enabled','1');
    if(!agent_katex_available()) {
        if(getenv('HOWHUB_REQUIRE_KATEX_CI')==='1') {
            throw new RuntimeException('CI required locally installed KaTeX, but it is missing');
        }
        echo "Optional local KaTeX unavailable; built-in MathML fallback passed\n";
    } else {
        $advanced=agent_tex_mathml('\\binom{n}{k}');
        if(!str_contains($advanced,'<math') ||
           !str_contains($advanced,'<mfrac') ||
           str_contains($advanced,'<script') ||
           str_contains($advanced,'<annotation')) {
            throw new RuntimeException('Offline KaTeX math rendering did not produce safe enhanced MathML');
        }
        $cases=agent_tex_mathml('\\begin{cases}x^2&\\text{当 x 大于 0}\\\\0&\\text{其它}\\end{cases}');
        if(!str_contains($cases,'<mtable') || !str_contains($cases,'<math')) {
            throw new RuntimeException('KaTeX failed to render multi-line formula');
        }
        foreach([
            '\\href{https://other.example/path}{click}',
            '\\includegraphics{https://other.example/test.png}',
            '\\htmlClass{evil}{x}',
            '\\newcommand{\\test}{x}',
            str_repeat('x',1901),
        ] as $bad) {
            if(agent_katex_mathml($bad)!=='')
                throw new RuntimeException('Untrusted TeX command was passed through KaTeX');
        }
        if(!str_contains(agent_tex_mathml('\\frac{1}{2}'),'<mfrac'))
            throw new RuntimeException('Advanced renderer broke basic formulas');
        echo "Offline KaTeX passed: advanced binomial MathML, cases, untrusted URL rejection and native fallback\n";
    }
} finally {
    set_setting('agent_katex_enabled',$old);
}
