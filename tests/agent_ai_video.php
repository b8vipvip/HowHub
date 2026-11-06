<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=database();
$keys=['agent_ai_video_base','agent_ai_video_key_cipher','agent_ai_video_model',
    'agent_ai_video_enabled','agent_ai_video_daily','agent_ai_video_request_format'];
$prior=[];
foreach($keys as $key) $prior[$key]=setting($key,'');
$created=[];$assets=[];
try {
    set_setting('agent_ai_video_base','https://video-fixture.example.org/v1');
    set_setting('agent_ai_video_key_cipher',ai_encrypt('ci-video-key'));
    set_setting('agent_ai_video_model','video-model-fixture');
    set_setting('agent_ai_video_enabled','1');
    set_setting('agent_ai_video_daily','2');
    set_setting('agent_ai_video_request_format','json');
    $title='CI异步视频原创示例'.bin2hex(random_bytes(5));
    if(!agent_topic_enqueue($pdo,$title,'','manual','教育','zh')) {
        throw new RuntimeException('Could not create test topic');
    }
    $q=$pdo->prepare('SELECT * FROM agent_topic_ideas WHERE raw_title=?');
    $q->execute([$title]);$idea=$q->fetch();$id=(int)$idea['id'];$created[]=$id;
    $html='<h2>全新原创教程示例</h2><h3>解释安全检查方法</h3><ol><li>独立描述第一个安全操作要点</li><li>核对所有变更不会损坏设备</li></ol>'.
        '<p>'.str_repeat('只使用独立编写的操作内容，',12).'</p>';
    $pdo->prepare("UPDATE agent_topic_ideas SET status='review',title=?,html=? WHERE id=?")
        ->execute(['安全的原创新手操作教程',$html,$id]);
    $q->execute([$title]);$idea=$q->fetch();
    agent_ai_video_enqueue($pdo,$id);
    $stmt=$pdo->prepare('SELECT * FROM agent_ai_video_jobs WHERE idea_id=?');
    $stmt->execute([$id]);$job=$stmt->fetch();
    if(!$job || $job['status']!=='queued') throw new RuntimeException('Opt-in video job not queued');
    $calls=[];
    $transport=static function(string $action,array $payload) use (&$calls):array|string {
        $calls[]=$action;
        if($action==='start') {
            if(str_contains($payload['prompt'],'source_url') ||
                str_contains($payload['prompt'],'http://') ||
                !str_contains($payload['prompt'],'独立描述第一个安全操作要点')) {
                throw new RuntimeException('AI video prompt included source site or omitted original guide steps');
            }
            return ['id'=>'job_CI_safe_01','status'=>'queued'];
        }
        if($action==='check') return ['id'=>'job_CI_safe_01','status'=>'completed'];
        if($action==='download') return "\x00\x00\x00\x18ftypisom".str_repeat("\0",320);
        throw new RuntimeException('Unexpected provider operation');
    };
    $t=1900000000;
    $start=agent_ai_video_process($pdo,$job,$idea,$transport,$t);
    if($start['status']!=='running' || $start['remote_id']!=='job_CI_safe_01') {
        throw new RuntimeException('Remote creation did not persist task identity');
    }
    $stmt->execute([$id]);$job=$stmt->fetch();
    $poll=agent_ai_video_process($pdo,$job,$idea,$transport,$t+120);
    if($poll['status']!=='ready') throw new RuntimeException('Video polling did not register ready state');
    $stmt->execute([$id]);$job=$stmt->fetch();
    $done=agent_ai_video_process($pdo,$job,$idea,$transport,$t+245);
    if($done['status']!=='complete' || !str_starts_with($done['url'],'/file/')) {
        throw new RuntimeException('Safe private video archive failed');
    }
    $assets[]=$done['url'];
    if($calls!==['start','check','download']) throw new RuntimeException('Unexpected extra video billable calls');
    $stmt->execute([$id]);$job=$stmt->fetch();
    if(agent_ai_video_process($pdo,$job,$idea,$transport,$t+400)['status']!=='idle') {
        throw new RuntimeException('Already archived job was processed twice');
    }
    if($calls!==['start','check','download']) throw new RuntimeException('Downloaded a file repeatedly');
    $q->execute([$title]);$idea=$q->fetch();
    if(!str_contains($idea['html'],'/media/stream/') ||
        !str_contains($idea['html'],'概念动画') || str_contains($idea['html'],'source_url')) {
        throw new RuntimeException('Generated video player not attached with distinct caution label');
    }
    try{agent_ai_video_job_validate(['id'=>'https://evil.example/xx','status'=>'completed']);
        throw new LogicException('Unsafe ID accepted');
    }catch(RuntimeException $e){}
    try{agent_ai_video_job_validate(['id'=>'job_CI_safe_01','status'=>'unknown']);
        throw new LogicException('Unsafe status accepted');
    }catch(RuntimeException $e){}
    $second='CI异步视频中断测试'.bin2hex(random_bytes(5));
    if(!agent_topic_enqueue($pdo,$second,'','manual','教育','zh'))throw new RuntimeException('Second topic failed');
    $q->execute([$second]);$idea=$q->fetch();$id2=(int)$idea['id'];$created[]=$id2;
    $pdo->prepare("UPDATE agent_topic_ideas SET status='review',title=?,html=? WHERE id=?")
        ->execute(['独立测试视频超时的原创教程',$html,$id2]);
    $q->execute([$second]);$idea2=$q->fetch();
    agent_ai_video_enqueue($pdo,$id2);
    $stmt->execute([$id2]);$job2=$stmt->fetch();
    $failure=agent_ai_video_process($pdo,$job2,$idea2,
        static function(string $action,array $payload):array {
            throw new RuntimeException('provider connection lost after possible billing');
        },$t+450);
    if($failure['status']!=='failed') throw new RuntimeException('Ambiguous creation must stop, never automatically retry');
    $stmt->execute([$id2]);$job2=$stmt->fetch();
    if($job2['remote_id']!=='' || $job2['status']!=='failed') {
        throw new RuntimeException('Ambiguous job state must be preserved for manual investigation');
    }
    if(agent_ai_video_process($pdo,$job2,$idea2,$transport,$t+600)['status']!=='idle') {
        throw new RuntimeException('Automatic re-creation after provider timeout could double bill');
    }
    echo "Original AI video passed: queued, polling, local MP4, no source URL, failure no retries, safe IDs\n";
} finally {
    foreach($created as $id) {
        $pdo->prepare('DELETE FROM agent_topic_ideas WHERE id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM agent_ai_video_budget WHERE idea_id=?')->execute([$id]);
    }
    foreach($assets as $url) {
        $q=$pdo->prepare('SELECT id FROM media_assets WHERE url=?');$q->execute([$url]);
        $assetId=$q->fetchColumn();
        if($assetId) media_delete($pdo,(int)$assetId);
    }
    foreach($prior as $key=>$value) set_setting($key,$value);
}
