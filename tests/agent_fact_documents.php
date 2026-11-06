<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=database();$ideaId=0;$sourceId=0;
try {
    $title='CI原始证据安全检验'.bin2hex(random_bytes(5));
    if(!agent_topic_enqueue($pdo,$title,'','manual','技术','zh')) {
        throw new RuntimeException('Cannot create source verification test topic');
    }
    $q=$pdo->prepare('SELECT * FROM agent_topic_ideas WHERE raw_title=?');
    $q->execute([$title]);$ideaId=(int)$q->fetch()['id'];
    $claim='安全排查网络故障应先检查网线和路由器的供电状态';
    $pdo->prepare("INSERT INTO agent_topic_quality(idea_id,status,issues_json)
        VALUES (?,'needs_review',?)")->execute([
        $ideaId,json_encode(['claims'=>[['claim'=>$claim,'finding'=>'unverified',
            'source_indexes'=>[],'reason'=>'没有原文证据']]],JSON_UNESCAPED_UNICODE),
    ]);
    $sourceId=agent_add_source($pdo,'https://trusted-docs.example.org','','网络','zh','summary');
    $pdo->prepare("UPDATE agent_sources SET enabled=1,robots_state='available',
        robots_text='User-agent: HowHubDiscoveryBot\nAllow: /',robots_checked=?,next_fetch=0 WHERE id=?")
        ->execute([time(),$sourceId]);
    $path='https://trusted-docs.example.org/manual/connection';
    $text=str_repeat('对于一般的连接问题，官方说明要求先检查供电和线路是否松动，确认网络设备通电以后再排查配置。',8);
    $count=0;$captured=[];
    $fixture=static function(string $url)use(&$count,$path,$text):array {
        if($url!==$path) throw new RuntimeException('Unexpected unapproved network destination');
        $count++;
        return ['status'=>200,'headers'=>['content-type'=>'text/html'],
            'body'=>'<html><head><title>公开官方操作手册</title></head><body><article>'.
                '<script>window.evil=true</script><h1>网络排查</h1><p>'.$text.'</p></article></body></html>'];
    };
    $model=static function(string $system,string $user)use(&$captured):array {
        $captured=[$system,$user];
        if(!str_contains($user,'检查供电') || str_contains($user,'window.evil')) {
            throw new RuntimeException('Review model saw scripts or missed visible evidence');
        }
        return ['verdict'=>'supports','reason'=>'该公开资料的可见正文文字描述了先检查供电与线路'];
    };
    $out=agent_fact_document_add($pdo,$ideaId,0,$sourceId,$path,$fixture,$model);
    if($out['status']!=='supports' || $count!==1) throw new RuntimeException('Original source review was not fetched once');
    $records=agent_fact_documents_summary($pdo,$ideaId);
    if(count($records)!==1 || $records[0]['source_url']!==$path ||
        !preg_match('/^[a-f0-9]{64}$/D',$records[0]['sha256']) ||
        str_contains($records[0]['excerpt'],'window.evil')) {
        throw new RuntimeException('Evidence audit did not persist bounded clean provenance');
    }
    $duplicate=false;
    try{agent_fact_document_add($pdo,$ideaId,0,$sourceId,$path,$fixture,$model);}
    catch(RuntimeException $e){$duplicate=true;}
    if(!$duplicate || $count!==1) throw new RuntimeException('Duplicate source URL was visited again');
    $foreign=false;
    try{agent_fact_document_add($pdo,$ideaId,0,$sourceId,'https://other-source.example.org/x',$fixture,$model);}
    catch(RuntimeException $e){$foreign=true;}
    if(!$foreign || $count!==1) throw new RuntimeException('Unapproved domain SSRF boundary failed');
    if(agent_fact_model_verdict($claim,['excerpt'=>$text],
        static fn(string $s,string $u):array=>['verdict'=>'unclear','reason'=>'没有特定设备型号'])['verdict']!=='unclear') {
        throw new RuntimeException('Uncertain evidence was incorrectly forced to supports');
    }
    $rejected=false;
    try {agent_fact_page_excerpt('<html><script>x</script></html>','text/html');}
    catch(RuntimeException $e) {$rejected=true;}
    if(!$rejected) throw new RuntimeException('Invalid source document was accepted');
    echo "Source fact evidence passed: approved site only, one bounded request, no JS, private digest and dedupe\n";
} finally {
    if($ideaId) $pdo->prepare('DELETE FROM agent_topic_ideas WHERE id=?')->execute([$ideaId]);
    if($sourceId) $pdo->prepare('DELETE FROM agent_sources WHERE id=?')->execute([$sourceId]);
}
