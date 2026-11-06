<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=database();
$oldOn=setting('agent_fact_auto_enabled','0');
$oldDaily=setting('agent_fact_auto_daily','2');
$ideaId=0;$sourceIds=[];$taskIds=[];$extraIdeas=[];
try {
    set_setting('agent_fact_auto_enabled','1');
    set_setting('agent_fact_auto_daily','2');
    $title='CI严格审批来源自动核验'.bin2hex(random_bytes(4));
    if(!agent_topic_enqueue($pdo,$title,'','manual','教程','zh')) {
        throw new RuntimeException('Cannot enqueue original idea');
    }
    $get=$pdo->prepare('SELECT * FROM agent_topic_ideas WHERE raw_title=?');
    $get->execute([$title]);$ideaId=(int)$get->fetch()['id'];
    $pdo->prepare("UPDATE agent_topic_ideas SET status='review',title=?,html=? WHERE id=?")
        ->execute(['原创教程中需要核验的安全操作',
            '<h2>原创操作说明</h2><p>'.str_repeat('务必检查设备电源和网络线缆状态。',18).'</p>',$ideaId]);
    $claim='排查普通网络故障时先检查供电与接线，再调整复杂的配置参数';
    $urls=[
        'https://approved-one.example.org/guide/connection',
        'https://approved-two.example.net/help/connection',
        'https://unapproved.example.com/guide',
    ];
    $insertReview=$pdo->prepare("INSERT INTO agent_topic_quality
        (idea_id,status,issues_json,evidence_json)
        VALUES (?,'needs_review',?,?)");
    $insertReview->execute([
        $ideaId,
        json_encode(['claims'=>[['claim'=>$claim,'finding'=>'unverified',
            'source_indexes'=>[],'reason'=>'需要原始文档核查']]],JSON_UNESCAPED_UNICODE),
        json_encode([
            ['url'=>$urls[0],'domain'=>'approved-one.example.org','snippet'=>'需要核对电源'],
            ['url'=>$urls[1],'domain'=>'approved-two.example.net','snippet'=>'先确认电源'],
            ['url'=>$urls[2],'domain'=>'unapproved.example.com','snippet'=>'不允许访问'],
        ],JSON_UNESCAPED_UNICODE),
    ]);
    foreach(['https://approved-one.example.org','https://approved-two.example.net'] as $origin) {
        $sid=agent_add_source($pdo,$origin,'','网络','zh','summary');$sourceIds[]=$sid;
        $pdo->prepare("UPDATE agent_sources SET enabled=1,robots_state='available',
            robots_checked=?,robots_text=?,next_fetch=0,blocked_until=0 WHERE id=?")
           ->execute([time(),'User-agent: HowHubDiscoveryBot'."\nAllow: /",$sid]);
    }
    $added=agent_fact_auto_plan($pdo,$ideaId);
    if($added!==2 || agent_fact_auto_plan($pdo,$ideaId)!==0) {
        throw new RuntimeException('Only approved distinct domains should produce at most two tasks');
    }
    $tasks=agent_fact_auto_for_idea($pdo,$ideaId);
    if(count($tasks)!==2 || str_contains(json_encode($tasks),'unapproved.example.com')) {
        throw new RuntimeException('Unapproved URL was queued for autonomous fetching');
    }
    foreach($tasks as $t) $taskIds[]=(int)$t['id'];
    $calls=[];
    $http=static function(string $url)use(&$calls,$urls):array {
        if(!in_array($url,array_slice($urls,0,2),true)) {
            throw new RuntimeException('Automatic Agent fetched outside approved domains');
        }
        $calls[]=$url;
        return ['status'=>200,'headers'=>['content-type'=>'text/html'],
            'body'=>'<html><head><title>已批准站点的文档</title></head>'.
                '<body><article><h1>基本网络检查流程</h1><p>'.
                str_repeat('排查网络故障时应检查电源和网线连接，再依据具体设备说明逐步排查配置。',10).
                '</p></article></body></html>'];
    };
    $verdict=static function(string $system,string $user):array {
        if(!str_contains($user,'网线连接') || !str_contains($system,'独立来源')) {
            throw new RuntimeException('Document evidence reviewer missing relevant data');
        }
        return ['verdict'=>'supports','reason'=>'可见正文描述先检查供电与网络接线'];
    };
    $result=agent_fact_auto_step($pdo,$http,$verdict);
    if(($result['status']??'')!=='supports' || count($calls)!==1) {
        throw new RuntimeException('One automatic step must fetch exactly one approved document');
    }
    $review=$pdo->prepare('SELECT status,risk FROM agent_topic_quality WHERE idea_id=?');
    $review->execute([$ideaId]);$quality=$review->fetch();
    if($quality['status']!=='needs_review') {
        throw new RuntimeException('Positive AI claim checks must NEVER auto-approve or publish');
    }
    $documents=agent_fact_documents_summary($pdo,$ideaId);
    if(count($documents)!==1 || $documents[0]['verdict']!=='supports') {
        throw new RuntimeException('Primary-source evidence not recorded');
    }
    set_setting('agent_fact_auto_daily','1');
    if(agent_fact_auto_step($pdo,$http,$verdict)!==null || count($calls)!==1) {
        throw new RuntimeException('Automatic evidence budget exceeded and re-fetched a site');
    }
    set_setting('agent_fact_auto_enabled','0');
    if(agent_fact_auto_step($pdo,$http,$verdict)!==null) {
        throw new RuntimeException('Disabled evidence agent must perform no autonomous work');
    }
    // A revised quality review must invalidate old claim-index snapshots, then
    // allow a fresh bounded plan without the old tasks counting against it.
    set_setting('agent_fact_auto_enabled','1');
    $revisedTime=time()+9;
    $pdo->prepare('UPDATE agent_topic_quality SET checked_at=? WHERE idea_id=?')
        ->execute([$revisedTime,$ideaId]);
    $againCount=agent_fact_auto_plan($pdo,$ideaId);
    if($againCount!==2) {
        throw new RuntimeException('Revised quality audit could not safely re-plan older source URLs');
    }
    $taskStates=$pdo->prepare('SELECT COUNT(*) FROM agent_fact_auto_tasks
        WHERE idea_id=? AND quality_checked_at=? AND status=?');
    $taskStates->execute([$ideaId,$revisedTime,'queued']);
    if((int)$taskStates->fetchColumn()!==2) {
        throw new RuntimeException('Replanned evidence tasks do not match the revised quality snapshot');
    }
    // When evidence suggestions refer only to unapproved sites, the planner
    // records an unmatched scan rather than repeatedly rescanning per tick.
    $unmatched='CI未匹配受控来源'.bin2hex(random_bytes(5));
    if(!agent_topic_enqueue($pdo,$unmatched,'','manual','知识','zh')) {
        throw new RuntimeException('Could not create unmatched evidence idea');
    }
    $get->execute([$unmatched]);$extraId=(int)$get->fetch()['id'];
    $extraIdeas[]=$extraId;
    $pdo->prepare("UPDATE agent_topic_ideas SET status='review' WHERE id=?")->execute([$extraId]);
    $pdo->prepare("INSERT INTO agent_topic_quality(idea_id,status,issues_json,evidence_json,checked_at)
        VALUES(?,'needs_review',?,?,?)")->execute([
            $extraId,
            json_encode(['claims'=>[['claim'=>$claim,'finding'=>'unverified','source_indexes'=>[]]]],JSON_UNESCAPED_UNICODE),
            json_encode([['url'=>'https://not-approved.example.com/guide','domain'=>'not-approved.example.com']],JSON_UNESCAPED_UNICODE),
            time(),
        ]);
    if(agent_fact_auto_plan($pdo,$extraId)!==0 || agent_fact_auto_plan($pdo,$extraId)!==0) {
        throw new RuntimeException('Unapproved evidence was unexpectedly added to the task queue');
    }
    $plan=$pdo->prepare('SELECT candidate_count,scanned_at FROM agent_fact_auto_plans WHERE idea_id=?');
    $plan->execute([$extraId]);$planned=$plan->fetch();
    if(!$planned || (int)$planned['candidate_count']!==0 || (int)$planned['scanned_at']<time()-30) {
        throw new RuntimeException('Unmatched source plan did not persist its negative result');
    }
    echo "Automatic primary-source evidence passed: opt-in, approved domains, one fetch/tick, budget and no auto-approval\n";
} finally {
    if($ideaId) $pdo->prepare('DELETE FROM agent_topic_ideas WHERE id=?')->execute([$ideaId]);
    foreach($extraIdeas as $extra) $pdo->prepare('DELETE FROM agent_topic_ideas WHERE id=?')->execute([$extra]);
    foreach($taskIds as $tid) {
        $pdo->prepare('DELETE FROM agent_fact_auto_runs WHERE task_id=?')->execute([$tid]);
    }
    foreach($sourceIds as $sid) $pdo->prepare('DELETE FROM agent_sources WHERE id=?')->execute([$sid]);
    set_setting('agent_fact_auto_enabled',$oldOn);
    set_setting('agent_fact_auto_daily',$oldDaily);
}
