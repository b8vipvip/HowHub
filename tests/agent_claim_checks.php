<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

$evidence=[
 ['domain'=>'docs-one.example.org','title'=>'设备接入说明','snippet'=>'检查电源和网络连接','url'=>'https://docs-one.example.org/setup'],
 ['domain'=>'manual-two.example.net','title'=>'设备故障指南','snippet'=>'先核对线路及供电情况','url'=>'https://manual-two.example.net/guide'],
 ['domain'=>'docs-one.example.org','title'=>'相同域名页','snippet'=>'重复的域名','url'=>'https://docs-one.example.org/more'],
];
$normal=[
 'risk'=>'low','pass'=>true,'issues'=>[],'checklist'=>['核对型号'],
 'claims'=>[
   ['claim'=>'基础故障排查前先核对电源和网络连接状态','finding'=>'corroborating_snippets',
    'source_indexes'=>[1,2], 'reason'=>'两个站点索引摘要都提出先检查连接情况'],
 ],
 'notes'=>'未发现显著风险，但仍需人工检查',
];
$pass=agent_topic_quality_parse($normal,$evidence);
if($pass['status']!=='reviewed' || $pass['claims'][0]['finding']!=='corroborating_snippets' ||
   $pass['claims'][0]['source_indexes']!==[1,2]) {
   throw new RuntimeException('Distinct-domain search clues not reflected in per-claim audit');
}
$duplicate=$normal;
$duplicate['claims'][0]['source_indexes']=[1,3];
$invalid=agent_topic_quality_parse($duplicate,$evidence);
if($invalid['status']!=='needs_review' || $invalid['claims'][0]['finding']!=='unverified') {
    throw new RuntimeException('Repeated-domain evidence must not count as independent corroboration');
}
$missing=$normal;
$missing['claims'][0]['source_indexes']=[99,0,'1',-1];
$invalid=agent_topic_quality_parse($missing,$evidence);
if($invalid['status']!=='needs_review' || $invalid['claims'][0]['source_indexes']!==[]) {
    throw new RuntimeException('Invented or untyped model source indexes must be rejected');
}
$contradiction=$normal;
$contradiction['claims'][0]['finding']='conflicting_snippets';
$contradiction['claims'][0]['source_indexes']=[1];
$risky=agent_topic_quality_parse($contradiction,$evidence);
if($risky['status']!=='needs_review' || $risky['risk']!=='high') {
    throw new RuntimeException('Contradictory search clue did not hold and escalate tutorial risk');
}
$noEvidence=agent_topic_quality_parse($normal,[]);
if($noEvidence['status']!=='needs_review' || $noEvidence['claims'][0]['finding']!=='unverified') {
    throw new RuntimeException('AI must not claim external corroboration without any real search evidence');
}
$bad=$normal;
$bad['claims'][0]['claim']='<script>alert(1)</script> 设备电源应当检查';
$parsed=agent_topic_quality_parse($bad,$evidence);
if(str_contains($parsed['claims'][0]['claim'],'<')) {
    throw new RuntimeException('Claim audit accepted executable model markup');
}
$old=[
 'risk'=>'low','pass'=>true,'issues'=>[], 'checklist'=>[],
 'notes'=>'Older model output has no claims yet',
];
$legacy=agent_topic_quality_parse($old,[]);
if($legacy['status']!=='reviewed') throw new RuntimeException('Legacy no-search model compatibility regressed');
$oldEvidence=agent_topic_quality_parse($old,$evidence);
if($oldEvidence['status']!=='needs_review') throw new RuntimeException('Model ignoring supplied search clues bypassed claim review');
echo "Claim audit passed: cross-domain corroboration is only a clue, contradictions escalate, missing indexes fail closed\n";
