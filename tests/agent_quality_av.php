<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=database();
$keys=['agent_original_audio_enabled','agent_original_video_enabled',
    'agent_original_audio_daily','agent_original_video_daily',
    'agent_review_daily_limit','agent_fact_search_daily_limit','agent_fact_search_enabled'];
$old=[];
foreach($keys as $key) $old[$key]=setting($key,'');
$id=0;$draftId=0;$files=[];
try {
    set_setting('agent_original_audio_enabled','1');
    set_setting('agent_original_video_enabled','1');
    set_setting('agent_review_daily_limit','8');
    set_setting('agent_fact_search_daily_limit','2');
    set_setting('agent_fact_search_enabled','1');
    $title='CI原创多媒体质量审查'.bin2hex(random_bytes(5));
    if (!agent_topic_enqueue($pdo,$title,'','manual','生活技巧','zh')) {
        throw new RuntimeException('Unable to create originality test idea');
    }
    $q=$pdo->prepare('SELECT * FROM agent_topic_ideas WHERE raw_title=?');
    $q->execute([$title]);$idea=$q->fetch();$id=(int)$idea['id'];
    $fake=[
        'eligible'=>true,'score'=>86,'title'=>'家庭网络操作检查原创教程',
        'summary'=>str_repeat('这篇原创文章按照通用的家庭网络连接排查逻辑，从设备接线、状态核对、基本配置和安全措施入手。',3),
        'sections'=>[
            ['heading'=>'开始前的设备准备','paragraphs'=>[str_repeat('检查电源和网络线路，确认操作前已记录现有设置，避免意外恢复出厂参数。',4)],
             'steps'=>['确认电源稳定','查看网线指示灯'],'table'=>null,'formula'=>'','diagram'=>''],
            ['heading'=>'逐项核对连接路径','paragraphs'=>[str_repeat('逐一排除单一设备与路由器之间的连接问题，避免一次修改多个设置。',4)],
             'steps'=>['对比另一设备连接状态','先记录再调整网络设置'],'table'=>null,'formula'=>'','diagram'=>''],
        ],
        'faq'=>[],'image_prompt'=>'','issues'=>[],'category'=>'技术基础',
    ];
    $made=agent_topic_process($pdo,$idea,static fn($a,$b):array=>$fake);
    if ($made['status']!=='review') throw new RuntimeException('Original idea generation failed');
    $q->execute([$title]);$idea=$q->fetch();
    $av=$pdo->prepare('SELECT * FROM agent_topic_av WHERE idea_id=?');
    $av->execute([$id]);$media=$av->fetch();
    if (!$media || $media['audio_status']!=='pending' || $media['video_status']!=='pending') {
        throw new RuntimeException('Optional video and audio were not queued');
    }
    try {agent_topic_approve($pdo,$id);throw new LogicException('approved before quality check');}
    catch(RuntimeException $e) {}

    $seenPrompt='';
    $search=static fn(string $query):array=>['web'=>['results'=>[
        ['url'=>'https://help-one.example.org/q','title'=>'网络连接检查','description'=>str_repeat('先确认设备联网状态再调整相关设置。',3)],
        ['url'=>'https://guide-two.example.net/a','title'=>'设备排查常见方法','description'=>str_repeat('建议依次检测接线和联网状态。',3)],
        ['url'=>'https://help-one.example.org/duplicate','title'=>'重复域名','description'=>str_repeat('不要重复请求同一网站。',3)],
    ]]];
    $reviewAi=static function(string $system,string $user) use (&$seenPrompt):array {
        $seenPrompt=$system."\n".$user;
        return ['risk'=>'high','pass'=>false,
            'issues'=>['配置恢复步骤缺少设备型号及备份条件'],
            'checklist'=>['核对路由器具体型号和官方说明'],
            'notes'=>'只有搜索摘要线索，必须人工确认安全步骤'];
    };
    $out=agent_topic_quality_process($pdo,$idea,$reviewAi,$search);
    if ($out['status']!=='needs_review'||$out['risk']!=='high') {
        throw new RuntimeException('High-risk independent review not held');
    }
    if (str_contains($seenPrompt,'DO_NOT_SEND_COPYRIGHTED_BODY_TO_AI') ||
        str_contains($seenPrompt,'source_url') || !str_contains($seenPrompt,'搜索摘要')) {
        throw new RuntimeException('Reviewer confused source page with original article');
    }
    $quality=$pdo->prepare('SELECT * FROM agent_topic_quality WHERE idea_id=?');
    $quality->execute([$id]);$state=$quality->fetch();
    $evidence=json_decode($state['evidence_json'],true);
    if (count($evidence)!==2 || !isset($evidence[0]['url']) ||
        $evidence[0]['verified']!==false) throw new RuntimeException('Source snippets must remain internal and unverified');
    try {agent_topic_approve($pdo,$id);throw new LogicException('accepted flagged article');}
    catch(RuntimeException $e) {}
    try {agent_topic_quality_waive($pdo,$id,'');throw new LogicException('accepted blank waiver');}
    catch(InvalidArgumentException $e) {}
    agent_topic_quality_waive($pdo,$id,'已人工检查本次操作仅描述安全的一般连接核对，具体设备参数仍须复核。');
    $quality->execute([$id]);$state=$quality->fetch();
    if ($state['status']!=='waived') throw new RuntimeException('Human override not persisted');

    $script=agent_topic_audio_script($idea);
    if (!str_contains($script,'设备准备') || mb_strlen($script)<60) throw new RuntimeException('Own-author script generation failed');
    $mockMp3='ID3'.str_repeat("\0",260);
    $audio=agent_topic_audio_process($pdo,$idea,static fn(string $script):string=>$mockMp3);
    if ($audio['status']!=='complete' || !str_starts_with($audio['url'],'/file/')) {
        throw new RuntimeException('Original audio archive failed');
    }
    $files[]=$audio['url'];
    $av->execute([$id]);$media=$av->fetch();
    if (!str_starts_with($media['audio_url'],'/file/')) throw new RuntimeException('Missing local original audio URL');
    $slides=agent_topic_video_storyboard($idea);
    if (count($slides)<2 || count($slides)>5 ||
        !str_contains($slides[0]['heading'],'原创教程')) {
        throw new RuntimeException('Original video storyboard invalid');
    }
    // Fixture validates safe local registration, never a remote download.
    $mockMp4="\x00\x00\x00\x18ftypisom".str_repeat("\0",300);
    $video=agent_topic_video_process($pdo,$idea,
        static fn(array $slides,string $audioFile):string=>$mockMp4);
    if ($video['status']!=='complete' || !str_starts_with($video['url'],'/file/')) {
        throw new RuntimeException('Original MP4 registration failed');
    }
    $files[]=$video['url'];
    $q->execute([$title]);$idea=$q->fetch();
    if (!str_contains($idea['html'],'原创教程配音') ||
        !str_contains($idea['html'],'原创知识演示视频') ||
        str_contains($idea['html'],'<video src="https://')) {
        throw new RuntimeException('Original media not delivered as local private downloads');
    }
    $draftId=agent_topic_approve($pdo,$id);
    $draft=$pdo->prepare('SELECT status,body_html FROM guides WHERE id=?');
    $draft->execute([$draftId]);$article=$draft->fetch();
    if ($article['status']!=='draft'||substr_count($article['body_html'],'/file/')!==2) {
        throw new RuntimeException('Original voice/video preview missing from private draft');
    }

    // An actual FFmpeg render is optional in minimal CI, but when FFmpeg and
    // a safe font are available we verify the MP4's real container signature.
    if (url_import_bin('ffmpeg')!=='' && is_file('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')) {
        $real=agent_topic_video_render([
            ['heading'=>'Original guide','body'=>'Read the instructions'],
            ['heading'=>'Step one','body'=>'Check the network connection'],
        ]);
        if (!str_contains(substr($real,0,32),'ftyp') || strlen($real)<3000) {
            throw new RuntimeException('FFmpeg did not produce a playable MP4 container');
        }
    }

    if (!agent_topic_quality_budget($pdo,'review',-789)) {
        throw new RuntimeException('First editorial review budget reservation failed');
    }
    if (!agent_topic_av_budget($pdo,'audio',-789)) {
        throw new RuntimeException('First voice budget reservation failed');
    }
    echo "Original review and AV passed: risk hold, search cues, waiver, TTS fixture, MP4 storage and optional FFmpeg render\n";
} finally {
    if ($draftId) $pdo->prepare('DELETE FROM guides WHERE id=?')->execute([$draftId]);
    if ($id) $pdo->prepare('DELETE FROM agent_topic_ideas WHERE id=?')->execute([$id]);
    foreach($files as $url) {
        $q=$pdo->prepare('SELECT id FROM media_assets WHERE url=?');$q->execute([$url]);
        $mediaId=$q->fetchColumn();
        if ($mediaId) media_delete($pdo,(int)$mediaId);
    }
    $pdo->exec('DELETE FROM agent_topic_av_runs WHERE idea_id=-789');
    $pdo->exec('DELETE FROM agent_topic_quality_runs WHERE idea_id=-789');
    foreach($old as $key=>$value) set_setting($key,$value);
}
