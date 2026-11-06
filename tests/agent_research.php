<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=database();

$originalGlobal=setting('agent_enabled','0');
$originalResearch=setting('agent_auto_source_enabled','0');
$originalKey=setting('agent_brave_key_cipher','');
$addedSources=[];
$addedGuides=[];
try {
    // The search adapter works on the provider's JSON: never fetch result URLs.
    set_setting('agent_enabled','1');
    set_setting('agent_auto_source_enabled','1');
    set_setting('agent_brave_key_cipher','');
    $providerCalls=0;
    $provider=static function(string $q) use (&$providerCalls):array {
        $providerCalls++;
        if (mb_strlen($q)<5) throw new RuntimeException('Search query missing');
        return ['web'=>['results'=>[
            ['url'=>'https://learning-ops.example.org/tutorial?chapter=2&utm_source=search','title'=>'How-to network config','description'=>'A practical device configuration guide'],
            ['url'=>'https://learning-ops.example.org/another-guide','title'=>'Duplicate origin','description'=>'Another document'],
            ['url'=>'http://127.0.0.1/internal','title'=>'Forbidden private address'],
            ['url'=>'https://external-hub.example.org/faq','title'=>'FAQ resource','description'=>'Common setup questions'],
        ]]];
    };
    $queryAi=static fn(string $prompt):string => 'software documentation practical tutorial troubleshooting how to';
    $result=agent_research_tick($pdo,$provider,$queryAi);
    if ($result['status']!=='proposed' || $result['method']!=='search_api' ||
        $result['added']!==2 || $providerCalls!==1) {
        throw new RuntimeException('API provider must create 2 unique, review-only domains');
    }
    $q=$pdo->query("SELECT * FROM agent_source_proposals WHERE origin='https://learning-ops.example.org'");
    $p=$q->fetch();
    if (!$p || $p['state']!=='pending' || $p['method']!=='search_api' ||
        !str_contains($p['example_url'],'chapter=2') || str_contains($p['example_url'],'utm_source')) {
        throw new RuntimeException('Proposal URL canonicalisation or review state invalid');
    }
    $before=(int)$pdo->query("SELECT COUNT(*) FROM agent_sources WHERE origin='https://learning-ops.example.org'")->fetchColumn();
    if ($before!==0) throw new RuntimeException('Research directly enabled unknown site');
    $sourceId=agent_research_accept($pdo,(int)$p['id']);
    $addedSources[]=$sourceId;
    $q=$pdo->prepare('SELECT enabled,rights_mode FROM agent_sources WHERE id=?');
    $q->execute([$sourceId]);
    $source=$q->fetch();
    if (!$source || $source['enabled'] || $source['rights_mode']!=='summary') {
        throw new RuntimeException('Human-reviewed proposals must become disabled summary-only sources');
    }
    try {agent_research_accept($pdo,(int)$p['id']);throw new LogicException('Accepted proposal was double-approved');}
    catch (RuntimeException $e) {}
    $result2=agent_research_tick($pdo,null,static fn(string $prompt):string =>
        json_encode(['sites'=>[['url'=>'https://ai-guess.example.org/tutorials','name'=>'AI guessed website','reason'=>'Appears relevant']]],JSON_UNESCAPED_UNICODE));
    if ($result2['status']!=='proposed' || $result2['method']!=='ai_suggestion' || $result2['added']!==1) {
        throw new RuntimeException('Unverified AI-only candidate fallback failed');
    }
    $ai=$pdo->query("SELECT * FROM agent_source_proposals WHERE origin='https://ai-guess.example.org'")->fetch();
    if (!$ai || $ai['method']!=='ai_suggestion' || $ai['state']!=='pending' ||
        $pdo->query("SELECT COUNT(*) FROM agent_sources WHERE origin='https://ai-guess.example.org'")->fetchColumn()) {
        throw new RuntimeException('AI-invented domain unexpectedly enabled or incorrectly labelled');
    }
    agent_research_reject($pdo,(int)$ai['id']);
    $pdo->prepare('DELETE FROM agent_source_proposals WHERE origin IN (?,?,?)')
        ->execute(['https://learning-ops.example.org','https://external-hub.example.org','https://ai-guess.example.org']);

    $sourceHtml='<html><head><meta name="author" content="Alicia Example">'.
        '<meta property="og:site_name" content="Open Example Documentation">'.
        '<meta property="article:published_time" content="2025-12-12">'.
        '<link rel="license" href="https://creativecommons.org/licenses/by/4.0/"></head>'.
        '<body><article><h2>网络操作教程</h2><p>操作前核实设备型号与网络连接。</p></article></body></html>';
    $sourceUrl='https://learning-ops.example.org/guide?version=2';
    $attribution=agent_attribution_from_page($sourceHtml,$sourceUrl,['rights_mode'=>'summary']);
    if ($attribution['author']!=='Alicia Example' ||
        $attribution['site_name']!=='Open Example Documentation' ||
        !str_contains($attribution['license_url'],'creativecommons.org') ||
        $attribution['mode']!=='summary') {
        throw new RuntimeException('Author/public license/exact URL attribution metadata missing');
    }
    $review=['title'=>'网络设置教程','summary'=>str_repeat('原文章说明了设置网络参数之前需要进行设备检查。',5),
        'points'=>['查看设备网络连接','按说明确认状态'],'concerns'=>['型号须独立核实']];
    $html=agent_summary_html($review,$sourceUrl,$attribution);
    if (!str_starts_with($html,'<blockquote class="hh-source-attribution"') ||
        strpos($html,'资料来源')>strpos($html,'网络设置教程') ||
        !str_contains($html,'Alicia Example') ||
        !str_contains($html,'并非整篇原文转载') ||
        !str_contains($html,'注明出处不等于取得转载许可')) {
        throw new RuntimeException('Source and copyright notice must precede Agent article body');
    }
    if (str_contains(agent_attribution_notice($attribution),'<script')) throw new RuntimeException('Unsafe metadata passed through attribution');
    $approvedUrl='https://docs-agent-attribution.example.org/how-to';
    $sid=agent_add_source($pdo,'https://docs-agent-attribution.example.org','','技术','en','summary');
    $addedSources[]=$sid;
    $stmt=$pdo->prepare("INSERT INTO agent_candidates (source_id,url,url_hash,title,status,body_html,format_notes,evidence)
       VALUES (?,?,?,'CI Agent Guide','review',?,?,?)");
    $snapshot=$attribution;
    $snapshot['source_url']=$approvedUrl;
    $stmt->execute([$sid,$approvedUrl,hash('sha256',$approvedUrl),
        agent_attribution_notice($snapshot).'<h2>配置方法</h2><p>'.str_repeat('检查安全设备设置，',8).'</p>',
        json_encode(['attribution'=>$snapshot,'licensed'=>false],JSON_UNESCAPED_UNICODE),
        json_encode(['category'=>'IT'],JSON_UNESCAPED_UNICODE)]);
    $cid=(int)$pdo->lastInsertId();
    $guideId=agent_approve_candidate($pdo,$cid);
    $addedGuides[]=$guideId;
    $restored=agent_preserve_source_notice($pdo,$guideId,'<h2>更新后的教程</h2><p>修改步骤需要保留原作者和来源。</p>');
    $twice=agent_preserve_source_notice($pdo,$guideId,$restored);
    if (substr_count($restored,'class="hh-source-attribution"')!==1 ||
        substr_count($twice,'class="hh-source-attribution"')!==1 ||
        strpos($restored,'资料来源')>strpos($restored,'更新后的教程')) {
        throw new RuntimeException('Later guide edits lost/doubled mandatory attribution');
    }

    $frac=agent_tex_mathml('\\frac{a+b}{c}');
    $sup=agent_tex_mathml('x_1^2+\\sqrt{y}');
    if (!str_contains($frac,'<mfrac>') || !str_contains($sup,'<msubsup>') ||
        !str_contains($sup,'<msqrt>')) throw new RuntimeException('TeX safe visual rendering failed');
    if (agent_tex_mathml('\\href{javascript:evil}{x}')!=='' || agent_tex_mathml('x^{}}')!=='') {
        throw new RuntimeException('Untrusted unsupported TeX was rendered');
    }
    $flow=agent_mermaid_linear_preview('graph TD; A[开始]-->B[设置]; B-->C[完成]');
    if (!str_contains($flow,'hh-flow-node') || !str_contains($flow,'开始') ||
        !str_contains($flow,'完成')) throw new RuntimeException('Safe Mermaid linear preview failed');
    if (agent_mermaid_linear_preview('graph TD; A-->B; A-->C')!=='') {
        throw new RuntimeException('Branching diagram cannot be misrepresented as linear');
    }
    $features=agent_source_features('<article><h2>可用公式和数据</h2><p>按照对应参数执行相关步骤。</p>'.
        '<math><mfrac><mi>a</mi><mn>2</mn></mfrac></math>'.
        '<div data-tex="x^2+1"></div>'.
        '<table><tr><td colspan="2">标题</td></tr><tr><td>值</td><td>100</td></tr></table>'.
        '<pre class="mermaid">graph TD; A[开始]-->B[完成]</pre></article>');
    $rich=agent_structured_html($features);
    if (!str_contains($rich,'<math') || !str_contains($rich,'<mfrac>') ||
        !str_contains($rich,'<msup>') || !str_contains($rich,'colspan="2"') ||
        !str_contains($rich,'hh-flow-preview') || str_contains($rich,'<script')) {
        throw new RuntimeException('Structured formula/table/diagram preserving output is incomplete');
    }
    echo "HowHub agent research passed: API domains/AI proposals/rate gate/human review/provenance/math/diagram\n";
} finally {
    foreach($addedGuides as $id) $pdo->prepare('DELETE FROM guides WHERE id=?')->execute([$id]);
    foreach($addedSources as $id) $pdo->prepare('DELETE FROM agent_sources WHERE id=?')->execute([$id]);
    foreach(['https://learning-ops.example.org','https://external-hub.example.org','https://ai-guess.example.org'] as $origin) {
        $pdo->prepare('DELETE FROM agent_source_proposals WHERE origin=?')->execute([$origin]);
    }
    set_setting('agent_enabled',$originalGlobal);
    set_setting('agent_auto_source_enabled',$originalResearch);
    set_setting('agent_brave_key_cipher',$originalKey);
}
