<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

$branch="graph TD; A[开始]-->B{是否连接}; B-->C[修复接线]; B-->D[检查设置]; C-->E[复测]; D-->E";
$html=agent_mermaid_diagram_preview($branch);
foreach(['hh-graph-preview','hh-graph-decision','连接关系','修复接线','检查设置','复测'] as $word) {
    if(!str_contains($html,$word)) throw new RuntimeException('Branched graph lost topology or visual nodes: '.$word);
}
if(substr_count($html,'hh-graph-node')!==5) throw new RuntimeException('Branched graph node count incorrect');
if(substr_count($html,' → ')!==5) throw new RuntimeException('Branched graph edge count incorrect');
if(agent_mermaid_diagram_preview('graph TD; A-->B; B-->A')!=='') {
    throw new RuntimeException('Cyclic graph wrongly shown in a layered DAG');
}
if(agent_mermaid_diagram_preview('graph TD; A-->B; click A "javascript:alert(1)"')!=='') {
    throw new RuntimeException('Untrusted JS Mermaid directive accepted');
}
if(agent_mermaid_diagram_preview('graph TD; A-->B; classDef danger fill:red')!=='') {
    throw new RuntimeException('Unsupported Mermaid CSS appeared partially rendered');
}

$tree="mindmap\n  root((网络故障))\n    设备\n      电源\n      网线\n    软件\n      配置\n      DNS";
$mind=agent_mermaid_diagram_preview($tree);
if(!str_contains($mind,'hh-mind-preview') || !str_contains($mind,'DNS') ||
    substr_count($mind,'hh-mind-item')!==7) {
    throw new RuntimeException('Nested mindmap structure did not render');
}
if(agent_mermaid_diagram_preview("mindmap\n  root((main))\n  another root")!=='') {
    throw new RuntimeException('Multiple mindmap roots must be rejected');
}
if(agent_mermaid_diagram_preview("mindmap\n  root((main))\n    <script>alert</script>")!=='') {
    throw new RuntimeException('Unsafe mindmap HTML was not rejected');
}

$sum=agent_tex_mathml('\\sum_{i=1}^{n}i^2');
if(!str_contains($sum,'<msubsup>') || !str_contains($sum,'∑')) {
    throw new RuntimeException('Sum with bounds did not render');
}
$matrix=agent_tex_mathml('\\begin{bmatrix}a&b\\\\c&d\\end{bmatrix}');
if(!str_contains($matrix,'<mtable>') || substr_count($matrix,'<mtr>')!==2 ||
   substr_count($matrix,'<mtd>')!==4) {
    throw new RuntimeException('Matrix not rendered in MathML');
}
$text=agent_tex_mathml('\\text{步骤} + x');
if(!str_contains($text,'<mtext>步骤</mtext>')) throw new RuntimeException('Safe CJK math label did not render');
if(str_contains(agent_tex_mathml('\\text{<script>}'),'<script>') ||
   agent_tex_mathml('\\begin{bmatrix}a&b\\\\c\\end{bmatrix}')!=='') {
    throw new RuntimeException('Invalid matrix or executable math markup was accepted');
}
$article=agent_topic_render([
    'eligible'=>true,'score'=>88,'title'=>'家庭网络连接排查的原创流程说明',
    'summary'=>str_repeat('为了更清晰地解释常见连接故障，先检查硬件再判断网络软件参数。',4),
    'sections'=>[
        ['heading'=>'决策流程说明','paragraphs'=>[str_repeat('根据不同设备状态选择不同分支，并记录问题复现方式。',6)],
            'steps'=>['检查物理连接','核查网络配置'],'diagram'=>$branch,'formula'=>'',
            'table'=>null],
        ['heading'=>'网络知识导图','paragraphs'=>[str_repeat('按照树状层级整理检查事项，清晰区分设备和配置问题。',6)],
            'steps'=>[],'diagram'=>$tree,'formula'=>'\\sum_{i=1}^{n}i^2',
            'table'=>null],
    ],
    'faq'=>[],'image_prompt'=>'','issues'=>[],'category'=>'网络排查',
]);
if(!str_contains($article['html'],'hh-graph-preview') ||
    !str_contains($article['html'],'hh-mind-preview') ||
    !str_contains($article['html'],'<msubsup>')) {
    throw new RuntimeException('Original multi-format article dropped rich diagram/math previews');
}
$piecewise=agent_tex_mathml('\begin{cases}x^2&\text{当 x 大于零}\\\\0&\text{否则}\end{cases}');
if(!str_contains($piecewise,'<mtable>') || !str_contains($piecewise,'<mo>{</mo>') ||
    substr_count($piecewise,'<mtr>')!==2 || !str_contains($piecewise,'当 x 大于零')) {
    throw new RuntimeException('Piecewise MathML function did not render: '.mb_substr($piecewise,0,400));
}
$aligned=agent_tex_mathml('\begin{aligned}x&=1\\\\y&=x+2\end{aligned}');
if(substr_count($aligned,'<mtr>')!==2 || substr_count($aligned,'<mtd>')!==4) {
    throw new RuntimeException('Aligned multi-line equations did not render');
}
$root=agent_tex_mathml('\sqrt[3]{x^2}');
if(!str_contains($root,'<mroot>') || !str_contains($root,'<msup>')) {
    throw new RuntimeException('Indexed radical did not render');
}
$accent=agent_tex_mathml('\vec{x}+\overline{AB}+\mathbb{R}');
if(substr_count($accent,'<mover>')!==2 || !str_contains($accent,'ℝ')) {
    throw new RuntimeException('Vectors, overline or blackboard constants were lost');
}
if(agent_tex_mathml('\begin{cases}x&y&z\end{cases}')!=='' ||
    agent_tex_mathml('\sqrt[not allowed]{x}')!=='' ||
    agent_tex_mathml('\mathbb{unsafe}')!=='') {
    throw new RuntimeException('Malformed or unsupported advanced math syntax accepted');
}
echo "Rich diagram/math passed: branched DAG, mindmap, sum, matrix, CJK labels, disallowed directives\n";
