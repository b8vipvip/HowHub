<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=database();
$ideaId=0;$sources=[];$oldDays=setting('agent_fact_evidence_days','30');
try {
    $title='CI独立原文证据一致性'.bin2hex(random_bytes(5));
    if(!agent_topic_enqueue($pdo,$title,'','manual','网络','zh')) throw new RuntimeException('No topic row');
    $stmt=$pdo->prepare('SELECT id FROM agent_topic_ideas WHERE raw_title=?');
    $stmt->execute([$title]);$ideaId=(int)$stmt->fetchColumn();
    $claim='排查普通网络故障时先检查设备供电和网线接线状态';
    $pdo->prepare("UPDATE agent_topic_ideas SET status='review' WHERE id=?")->execute([$ideaId]);
    $pdo->prepare("INSERT INTO agent_topic_quality (idea_id,status,issues_json,checked_at) VALUES (?,'needs_review',?,?)")
       ->execute([$ideaId,json_encode(['claims'=>[['claim'=>$claim,'finding'=>'unverified','source_indexes'=>[]]]],JSON_UNESCAPED_UNICODE),time()]);
    foreach(['https://docs-a.example.org','https://docs-b.example.net'] as $origin) {
        $id=agent_add_source($pdo,$origin,'','文档','zh','summary');
        $sources[]=$id;
        $pdo->prepare('UPDATE agent_sources SET enabled=1 WHERE id=?')->execute([$id]);
    }
    $now=time();
    $record=$pdo->prepare('INSERT INTO agent_fact_documents (
       idea_id,source_id,claim_text,source_url,source_host,excerpt,sha256,verdict,
       retrieved_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $record->execute([$ideaId,$sources[0],$claim,'https://docs-a.example.org/guide1',
        'docs-a.example.org','可核对的官方说明',str_repeat('a',64),'supports',$now,$now]);
    $state=agent_fact_consensus_for_idea($pdo,$ideaId,$now)[0];
    if($state['status']!=='single_source_support' || $state['support_count']!==1)
        throw new RuntimeException('One site improperly counted as two independent origins');
    $record->execute([$ideaId,$sources[0],$claim,'https://docs-a.example.org/guide2',
        'docs-a.example.org','相同域的第二份文档',str_repeat('b',64),'supports',$now-10,$now-10]);
    $state=agent_fact_consensus_for_idea($pdo,$ideaId,$now)[0];
    if($state['support_count']!==1) throw new RuntimeException('Multiple pages on same host double counted');
    $record->execute([$ideaId,$sources[1],$claim,'https://docs-b.example.net/guide1',
        'docs-b.example.net','其它独立网站',str_repeat('c',64),'supports',$now,$now]);
    $state=agent_fact_consensus_for_idea($pdo,$ideaId,$now)[0];
    if($state['status']!=='cross_source_support' || $state['support_count']!==2)
        throw new RuntimeException('Two distinct enabled hosts were not classified as corroborating clues');
    $pdo->prepare("UPDATE agent_fact_documents SET verdict='conflicts' WHERE source_id=?")->execute([$sources[1]]);
    $state=agent_fact_consensus_for_idea($pdo,$ideaId,$now)[0];
    if($state['status']!=='conflicting_evidence' || $state['conflict_count']!==1)
        throw new RuntimeException('Contradictory primary source did not take precedence over support');
    $pdo->prepare("UPDATE agent_fact_documents SET verdict='supports' WHERE source_id=?")->execute([$sources[1]]);
    $pdo->prepare('UPDATE agent_sources SET enabled=0 WHERE id=?')->execute([$sources[1]]);
    $state=agent_fact_consensus_for_idea($pdo,$ideaId,$now)[0];
    if($state['status']!=='single_source_support' || $state['disabled_count']!==1)
        throw new RuntimeException('Disabled formerly trusted source still counted as live evidence');
    $pdo->prepare('UPDATE agent_sources SET enabled=1 WHERE id=?')->execute([$sources[1]]);
    set_setting('agent_fact_evidence_days','7');
    $pdo->prepare('UPDATE agent_fact_documents SET retrieved_at=? WHERE idea_id=?')
        ->execute([$now-9*86400,$ideaId]);
    $state=agent_fact_consensus_for_idea($pdo,$ideaId,$now)[0];
    if($state['status']!=='expired_evidence' || $state['expired_count']!==3)
        throw new RuntimeException('Expired original evidence remained valid');
    $qc=$pdo->prepare('SELECT status FROM agent_topic_quality WHERE idea_id=?');
    $qc->execute([$ideaId]);
    if($qc->fetchColumn()!=='needs_review')
        throw new RuntimeException('Aggregated original evidence automatically approved the draft');
    echo "Cross-source primary evidence passed: distinct hosts, conflicts, disabled origin, expiry and no auto-approval\n";
} finally {
    if($ideaId) $pdo->prepare('DELETE FROM agent_topic_ideas WHERE id=?')->execute([$ideaId]);
    foreach($sources as $id) $pdo->prepare('DELETE FROM agent_sources WHERE id=?')->execute([$id]);
    set_setting('agent_fact_evidence_days',$oldDays);
}
