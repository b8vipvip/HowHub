<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=database();
$keys=['agent_ai_video_base','agent_ai_video_key_cipher','agent_ai_video_model',
    'agent_ai_video_enabled','agent_ai_video_daily','agent_ai_video_request_format',
    'agent_ai_video_seconds','agent_ai_video_size','agent_ai_video_max_mb'];
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
    set_setting('agent_ai_video_seconds','60');
    set_setting('agent_ai_video_size','720x1280');
    set_setting('agent_ai_video_max_mb','64');
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
    $t=1900000000;
    $blockedReentry=0;
    $transport=static function(string $action,array $payload) use (&$calls,&$blockedReentry,$pdo,$stmt,$idea,$id,$t):array|string {
        $calls[]=$action;
        if($action==='start') {
            // Re-entrant second scheduler has a fresh running snapshot
            // but must never call the paid creation endpoint again.
            $stmt->execute([$id]);
            $concurrent=$stmt->fetch();
            if(!$concurrent || $concurrent['status']!=='running' ||
                $concurrent['lease_token']==='') {
                throw new RuntimeException('Creation was not durably claimed before paid API call');
            }
            $raced=agent_ai_video_process($pdo,$concurrent,$idea,
                static function(string $op,array $unused):array {
                    throw new RuntimeException('Second worker reached paid video provider: '.$op);
                },$t);
            if($raced['status']!=='busy') {
                throw new RuntimeException('Second worker was not blocked by exclusive video lease');
            }
            $blockedReentry++;
            $rejectSkip=false;
            try {
                agent_topic_admin_action($pdo,['action'=>'topic_ai_video_skip','id'=>$id]);
            } catch(RuntimeException $e) {
                $rejectSkip=str_contains($e->getMessage(),'正在执行');
            }
            if(!$rejectSkip) {
                throw new RuntimeException('Admin skipped a currently billed video task mid-flight');
            }
            if (($payload['seconds']??null)!==60 || ($payload['size']??'')!=='720x1280') {
                throw new RuntimeException('Long video request options not snapshotted for the new job');
            }
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
    $start=agent_ai_video_process($pdo,$job,$idea,$transport,$t);
    if($start['status']!=='running' || $start['remote_id']!=='job_CI_safe_01') {
        throw new RuntimeException('Remote creation did not persist task identity');
    }
    if($blockedReentry!==1) throw new RuntimeException('Concurrent paid-create was not exercised');
    $stmt->execute([$id]);
    $claimed=$stmt->fetch();
    if($claimed['lease_token']!=='' || (int)$claimed['lease_until']!==0) {
        throw new RuntimeException('Successful provider request retained the exclusive lease');
    }
    // A queued snapshot from a previous scheduler must not start after
    // the owner already transitioned it to running.
    $stale=agent_ai_video_process($pdo,$job,$idea,$transport,$t+1);
    if($stale['status']!=='busy') {
        throw new RuntimeException('Stale queue snapshots should not resubmit paid videos');
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
    // Reapplying an already delivered file cannot duplicate playback widgets.
    agent_topic_av_append($pdo,$id,'video',$done['url'],'generated');
    $q->execute([$title]);$same=$q->fetch();
    if(substr_count($same['html'],'<video')!==1) {
        throw new RuntimeException('Video delivery re-entry caused duplicated players');
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
    // Bounded disk streaming can archive videos LARGER than the old 18MB
    // in-memory limit, without changing the smaller slideshow output cap.
    $large="\x00\x00\x00\x18ftypisom".str_repeat("\0",19*1048576);
    $temporary=agent_ai_video_receive_mp4('job_CI_long_01',20,
        static fn(string $action,array $input):string=>$large);
    try {
        if(!is_file($temporary) || filesize($temporary)!==strlen($large)) {
            throw new RuntimeException('Provider video was not written to bounded temporary storage');
        }
        $url=agent_ai_video_register_mp4($pdo,$temporary,20);
        $assets[]=$url;
        $check=$pdo->prepare('SELECT * FROM media_assets WHERE url=?');$check->execute([$url]);
        $asset=$check->fetch();
        if(!$asset || $asset['byte_size']!==strlen($large) || !media_file_path($asset)) {
            throw new RuntimeException('Private archive failed for MP4 larger than old limit');
        }
    } finally {@unlink($temporary);}
    $blocked=false;
    try {
        agent_ai_video_receive_mp4('job_CI_long_01',1,
            static fn(string $action,array $input):string=>$large);
    } catch(RuntimeException $e) {$blocked=true;}
    if(!$blocked) throw new RuntimeException('Stream download exceeded per-job MB cap');
    // An exhausted paid-video budget must not monopolize scheduler ticks.
    $third='CI视频额度饥饿保护'.bin2hex(random_bytes(5));
    if(!agent_topic_enqueue($pdo,$third,'','manual','教育','zh')) {
        throw new RuntimeException('Third budget test idea missing');
    }
    $q->execute([$third]);$thirdIdea=$q->fetch();
    $created[]=(int)$thirdIdea['id'];
    $pdo->prepare("UPDATE agent_topic_ideas SET status='review',title=?,html=? WHERE id=?")
        ->execute(['原创视频生成配额边界检查',$html,(int)$thirdIdea['id']]);
    agent_ai_video_enqueue($pdo,(int)$thirdIdea['id']);
    set_setting('agent_ai_video_daily','0');
    if(agent_topic_next($pdo)!==null) {
        throw new RuntimeException('Paid video budget exhaustion kept returning a blocking scheduler task');
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
