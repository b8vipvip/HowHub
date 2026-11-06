<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=database();$id=0;$createdUrl='';$revisedUrl='';
$old=setting('agent_diagram_auto_enabled','0');
$oldDaily=setting('agent_diagram_daily','4');
try {
    $flow='graph TD; A[开始]-->B{判断}; B-->C[分支1]; B-->D[分支2]; C-->E[结束]; D-->E';
    $dot=agent_diagram_dot($flow);
    if(!str_contains($dot,'B -> C') || !str_contains($dot,'B -> D') ||
        !str_contains($dot,'C -> E') || !str_contains($dot,'shape=diamond')) {
        throw new RuntimeException('Graphviz DOT lost real branched flow relationships');
    }
    if(agent_diagram_dot('graph TD; A-->B; click A "https://external.example/"')!=='' ||
       agent_diagram_dot('graph TD; A[<img src=x>]-->B')!=='' ||
       agent_diagram_dot('graph TD; A-->B; B-->C; classDef threat fill:red')!=='') {
        throw new RuntimeException('Unsafe Mermaid custom directives entered the Graphviz parser');
    }
    $mind="mindmap\n  root((安全操作))\n    检查硬件\n      连接线\n    检查软件\n      网络配置";
    $tree=agent_diagram_dot($mind);
    if(!str_contains($tree,'N0 -> N1') || !str_contains($tree,'N1 -> N2') ||
        !str_contains($tree,'N0 -> N3')) {
        throw new RuntimeException('Indented mindmap edges were lost');
    }
    $name='CI高级绘图'.bin2hex(random_bytes(5));
    if(!agent_topic_enqueue($pdo,$name,'','manual','技术','zh')) throw new RuntimeException('Queue diagram test failed');
    $stmt=$pdo->prepare('SELECT * FROM agent_topic_ideas WHERE raw_title=?');
    $stmt->execute([$name]);$id=(int)$stmt->fetch()['id'];
    $article='<h2>安全原创流程</h2><p>'.str_repeat('请根据设备状况判断应该执行的排查步骤。',8).'</p>'.
        '<pre><code>'.h($flow).'</code></pre>';
    $pdo->prepare("UPDATE agent_topic_ideas SET status='review',title=?,html=? WHERE id=?")
        ->execute(['原创安全操作流程',$article,$id]);
    $bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5WzQAAAABJRU5ErkJggg==',true);
    $calls=0;
    $fixture=static function(string $safeDot)use($bytes,&$calls):string {
        $calls++;
        if(!str_contains($safeDot,'B -> D')) throw new RuntimeException('Expected validated DOT');
        return $bytes;
    };
    $done=agent_diagram_process($pdo,$id,$fixture);
    if($done['status']!=='complete' || !str_starts_with($done['url'],'/uploads/')) {
        throw new RuntimeException('Original diagram PNG not stored in local image library');
    }
    $createdUrl=$done['url'];
    $again=agent_diagram_process($pdo,$id,$fixture);
    if(!$again['reused'] || $calls!==1) throw new RuntimeException('Already rendered diagram performed repeated work');
    $stmt->execute([$name]);$article=$stmt->fetch()['html'];
    if(substr_count($article,'hh-diagram-raster')!==1 ||
        !str_contains($article,'<pre><code>') || !str_contains($article,$createdUrl)) {
        throw new RuntimeException('PNG renderer discarded editable source or duplicated preview');
    }
    // A second render after changing the authored Mermaid must replace
    // the obsolete figure; the original editable source remains intact.
    $newSource=str_replace('A[开始]','A[已更新的开始步骤]',$flow);
    $pdo->prepare('UPDATE agent_topic_ideas SET html=? WHERE id=?')
        ->execute([str_replace(h($flow),h($newSource),$article),$id]);
    $changed=agent_diagram_process($pdo,$id,$fixture);
    if($changed['status']!=='complete' || $changed['reused'] || $calls!==2) {
        throw new RuntimeException('Edited Mermaid source did not trigger a fresh visual render');
    }
    $revisedUrl=$changed['url'];
    $stmt->execute([$name]);$updated=$stmt->fetch()['html'];
    if(substr_count($updated,'hh-diagram-raster')!==1 ||
        str_contains($updated,$createdUrl) || !str_contains($updated,$revisedUrl) ||
        !str_contains($updated,'已更新的开始步骤')) {
        throw new RuntimeException('Old diagram remained after edited-source regeneration');
    }
    set_setting('agent_diagram_auto_enabled','1');
    set_setting('agent_diagram_daily','0');
    if(agent_diagram_next($pdo)!==null) throw new RuntimeException('Zero diagram budget allowed unexpected rendering');
    // Real Graphviz smoke test only when the local binary is installed.
    if(url_import_bin('dot')!=='') {
        $actual=agent_diagram_png($flow);
        if(!str_starts_with($actual,"\x89PNG\r\n\x1a\n") ||
            !@getimagesizefromstring($actual)) {
            throw new RuntimeException('Graphviz real raster test returned invalid PNG');
        }
    }
    echo "Optional original Graphviz passed: sanitized DOT, real edges, mindmap, local PNG, idempotent and rate limit\n";
} finally {
    set_setting('agent_diagram_auto_enabled',$old);
    set_setting('agent_diagram_daily',$oldDaily);
    if($id) $pdo->prepare('DELETE FROM agent_topic_ideas WHERE id=?')->execute([$id]);
    foreach([$createdUrl,$revisedUrl] as $url) {
        if($url==='') continue;
        $q=$pdo->prepare('SELECT id FROM media_assets WHERE url=?');$q->execute([$url]);
        $assetId=$q->fetchColumn();
        if($assetId) media_delete($pdo,(int)$assetId);
    }
}
