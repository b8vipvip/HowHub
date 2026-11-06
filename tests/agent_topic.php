<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$pdo=database();
$previousMode=setting('agent_creation_mode','');
$previousImage=setting('agent_original_image_enabled','0');
$previousGlobal=setting('agent_enabled','0');
$sourceId=0;$topicIds=[];$guideId=0;$imageUrl='';
try {
    set_setting('agent_creation_mode','topic_only');
    set_setting('agent_original_image_enabled','0');
    set_setting('agent_enabled','1');
    $sourceId=agent_add_source($pdo,'https://ci-title-feed.example.org','https://ci-title-feed.example.org/rss.xml','网络排障','zh','summary');
    $pdo->prepare("UPDATE agent_sources SET enabled=1,robots_state='available',robots_checked=?,robots_text=? WHERE id=?")
        ->execute([time(),"User-agent: HowHubDiscoveryBot\nAllow: /\n",$sourceId]);
    $stmt=$pdo->prepare('SELECT * FROM agent_sources WHERE id=?');
    $stmt->execute([$sourceId]);$source=$stmt->fetch();
    $fetched=[];
    $fixture=static function(string $url) use (&$fetched): array {
        $fetched[]=$url;
        if ($url!=='https://ci-title-feed.example.org/rss.xml') throw new RuntimeException('Full article fetch attempted: '.$url);
        return [
            'status'=>200,
            'headers'=>['content-type'=>'application/rss+xml'],
            'body'=>'<rss><channel><item><title>如何排查无线路由器连接故障？</title><link>https://ci-title-feed.example.org/how-to-wifi</link><description>DO_NOT_SEND_COPYRIGHTED_BODY_TO_AI</description></item></channel></rss>',
        ];
    };
    $found=agent_discover($pdo,$source,$fixture);
    if ($found['status']!=='discovered' || $found['added']!==1 ||
        $fetched!==['https://ci-title-feed.example.org/rss.xml']) {
        throw new RuntimeException('Feed topic-only ingestion must avoid linked article URLs');
    }
    $idea=$pdo->query("SELECT * FROM agent_topic_ideas WHERE source_method='feed_title' AND source_id=".$sourceId)->fetch();
    if (!$idea || $idea['raw_title']!=='如何排查无线路由器连接故障？' ||
        str_contains($idea['raw_title'],'DO_NOT_SEND')) {
        throw new RuntimeException('Only feed heading should enter idea queue');
    }
    $topicIds[]=(int)$idea['id'];
    if (agent_topic_enqueue($pdo,$idea['raw_title'],$idea['source_url'],'feed_title','网络排障','zh',$sourceId)) {
        throw new RuntimeException('Source heading was not deduplicated');
    }
    $approvedTitle='家用无线网络连接异常的分步检查方法';
    $originalContent='请先观察连接状态并确认路由器电源及设备网络开关，随后分段排查物理连接与无线设置。';
    $json=[
        'eligible'=>true,'score'=>91,'title'=>$approvedTitle,
        'summary'=>str_repeat('这份新编写的指南介绍家庭网络连接异常时的基础检查流程，强调先判断设备状态再逐项排查，',3),
        'sections'=>[
            [
                'heading'=>'检查供电和网络指示状态',
                'paragraphs'=>[str_repeat($originalContent,2)],
                'steps'=>['观察设备电源指示灯是否正常亮起','检查网线是否松动，再查看连接状态','记录仍无法联网的设备数量'],
                'table'=>['headers'=>['项目','检查动作'],'rows'=>[['设备供电','检查指示灯'],['连接线','检查插头']]],
                'formula'=>'x^2+y^2',
                'diagram'=>'graph TD; A[确认状态]-->B[检查网络]; B-->C[核实连接]',
            ],
            [
                'heading'=>'排除单台设备的配置问题',
                'paragraphs'=>[str_repeat($originalContent,2)],
                'steps'=>['在同一网络中对比另一台设备的连接情况','先记录原设置再考虑安全的恢复操作'],
                'table'=>['headers'=>['现象','建议'],'rows'=>[['单设备异常','优先检查本机设置']]],
                'formula'=>'','diagram'=>'',
            ],
        ],
        'faq'=>[['question'=>'需要立刻恢复出厂设置吗？','answer'=>'不建议在未经备份、不了解设备状态时直接恢复出厂设置。']],
        'image_prompt'=>'Original conceptual educational illustration of a home router and simplified wireless connection paths, no logos or identifiable device screenshot.',
        'issues'=>['不同厂商设备的管理菜单不一样，需要检查说明书'],
        'category'=>'网络技术',
    ];
    $request='';
    $ai=static function(string $system,string $user) use ($json,&$request): array {
        $request=$system."\n".$user;
        return $json;
    };
    $result=agent_topic_process($pdo,$idea,$ai);
    if ($result['status']!=='review' || $result['image_pending']!==false) {
        throw new RuntimeException('Independent AI original guide should enter review without media fetches');
    }
    if (str_contains($request,'ci-title-feed.example.org') ||
        str_contains($request,'DO_NOT_SEND_COPYRIGHTED_BODY_TO_AI') ||
        !str_contains($request,'如何排查无线路由器连接故障')) {
        throw new RuntimeException('Original article AI prompt included third-party URL/body or omitted topic');
    }
    $s=$pdo->prepare('SELECT * FROM agent_topic_ideas WHERE id=?');
    $s->execute([$idea['id']]);$updated=$s->fetch();
    $html=$updated['html'];
    if (!str_contains($html,'<table>') ||
        !str_contains($html,'<math') ||
        !str_contains($html,'hh-flow-preview') ||
        str_contains($html,'href="https://ci-title-feed') ||
        str_contains($html,'hh-source-attribution') ||
        str_contains($html,'资料来源：')) {
        throw new RuntimeException('Original article did not include self-generated rich HTML or mistakenly exposed source metadata');
    }
    $guideId=agent_topic_approve($pdo,(int)$idea['id']);
    $guide=$pdo->prepare('SELECT body_html,status FROM guides WHERE id=?');
    $guide->execute([$guideId]);$draft=$guide->fetch();
    if (!$draft || $draft['status']!=='draft' || str_contains($draft['body_html'],'资料来源：')) {
        throw new RuntimeException('Original article was published or added source attribution');
    }
    $html2=agent_preserve_source_notice($pdo,$guideId,$draft['body_html']);
    if (str_contains($html2,'hh-source-attribution')) {
        throw new RuntimeException('Original article must not receive legacy Agent source notices on edit');
    }
    $empty=agent_topic_render(['eligible'=>false,'issues'=>['Not an instructional topic']]);
    if ($empty['eligible'] || $empty['html']!=='') throw new RuntimeException('Non-tutorial prompt incorrectly accepted');
    $malicious=$json;
    $malicious['sections'][0]['paragraphs'][0]='<script>alert(1)</script> 网络检查应避免复制未知命令';
    $converted=agent_topic_render($malicious);
    if (str_contains($converted['html'],'<script>')) throw new RuntimeException('AI HTML injection was not sanitized');

    // Image API: can ONLY accept base64 content. A returned provider URL is rejected.
    try {
        agent_topic_image_api('A fully original router connection educational diagram with no logos',
            static fn(string $prompt):array=>['data'=>[['url'=>'https://external.example.com/copied.png']]]);
        throw new LogicException('Third-party image URL was accepted as original');
    } catch (RuntimeException $e) {}
    $png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5WzQAAAABJRU5ErkJggg==';
    $bytes=agent_topic_image_api('Original educational diagram of wireless connectivity, no logos',
        static fn(string $prompt):array=>['data'=>[['b64_json'=>$png]]]);
    if (!str_starts_with($bytes,"\x89PNG")) throw new RuntimeException('Base64 original image decoding failed');

    // A generated image task is separate from the original text generation.
    set_setting('agent_original_image_enabled','1');
    $manualTitle='CI家用网络原理分步图文教程'.bin2hex(random_bytes(4));
    if (!agent_topic_enqueue($pdo,$manualTitle,'','manual','IT','zh')) throw new RuntimeException('Unable to queue second original idea');
    $new=$pdo->query('SELECT * FROM agent_topic_ideas WHERE raw_title='.$pdo->quote($manualTitle))->fetch();
    $topicIds[]=(int)$new['id'];
    $generated=agent_topic_process($pdo,$new,$ai);
    if ($generated['status']!=='review' || !$generated['image_pending']) throw new RuntimeException('Original illustration job was not queued');
    $s->execute([$new['id']]);$new=$s->fetch();
    try { agent_topic_approve($pdo,(int)$new['id']);throw new LogicException('Approved before illustration completed');}
    catch (RuntimeException $e) {}
    $img=agent_topic_process_illustration($pdo,$new,
        static fn(string $prompt):array=>['data'=>[['b64_json'=>$png]]]);
    if ($img['status']!=='generated' || !str_starts_with($img['url'],'/uploads/')) {
        throw new RuntimeException('Original image was not stored as local asset');
    }
    $imageUrl=$img['url'];
    $s->execute([$new['id']]);$new=$s->fetch();
    if (!str_contains($new['html'],'/uploads/') || str_contains($new['html'],'https://external.example.com/copied.png')) {
        throw new RuntimeException('Original guide picture is missing or references a foreign site');
    }

    echo "Topic-only original Agent passed: feed headings / no full pages / original multi-format HTML / review only / no attribution / local AI image\n";
} finally {
    if ($guideId) $pdo->prepare('DELETE FROM guides WHERE id=?')->execute([$guideId]);
    foreach($topicIds as $id) $pdo->prepare('DELETE FROM agent_topic_ideas WHERE id=?')->execute([$id]);
    if ($sourceId) $pdo->prepare('DELETE FROM agent_sources WHERE id=?')->execute([$sourceId]);
    if ($imageUrl!=='') {
        $q=$pdo->prepare('SELECT id FROM media_assets WHERE url=?');
        $q->execute([$imageUrl]);
        $assetId=$q->fetchColumn();
        if ($assetId) media_delete($pdo,(int)$assetId);
    }
    set_setting('agent_enabled',$previousGlobal);
    set_setting('agent_creation_mode',$previousMode);
    set_setting('agent_original_image_enabled',$previousImage);
}
