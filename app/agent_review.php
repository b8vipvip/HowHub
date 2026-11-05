<?php
declare(strict_types=1);

/** Source evidence is evaluated without unbounded follow-up web requests. */
function agent_inspect_resource(array $response, array $source): array
{
    $mime = url_import_mime($response);
    $url = $response['url'];
    $body = $response['body'];
    $features = ['tables'=>[],'formulas'=>[],'diagrams'=>[],
        'counts'=>['tables'=>0,'formulas'=>0,'diagrams'=>0,'vector'=>0],'warnings'=>[]];
    if (in_array($mime,['text/html','application/xhtml+xml'],true)) {
        $doc = new DOMDocument('1.0','UTF-8');
        $previous=libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$body.'</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            $name=strtolower($meta->getAttribute('name'));
            if (!in_array($name,['robots','howhubdiscoverybot','googlebot','chatgpt-user'],true)) continue;
            if (preg_match('/\b(noindex|nosnippet|noarchive|noai)\b/i',$meta->getAttribute('content'))) {
                throw new RuntimeException('来源页面限制自动索引/摘要，已停止处理');
            }
        }
        $features = agent_source_features($body);
        $text = agent_article_excerpt($body);
        $meta = url_import_html_metadata($body,$url);
        if ($meta['video']) {
            $text .= "\n" . mb_substr($meta['transcript'],0,12000);
            if (mb_strlen($meta['transcript']) < 100) {
                $features['warnings'][]='视频无足够字幕：只评估页面中已有的操作文字，不分析未知音轨';
            }
        }
        return ['text'=>$text,'features'=>$features,'kind'=>$meta['video'] ? 'video_page' : 'html',
            'raw_html'=>$body,'title'=>$meta['title']];
    }
    if (in_array($mime,['text/plain','text/markdown','application/json'],true)) {
        $text=mb_substr($body,0,16000);
        if (mb_strlen(trim($text)) < 100) throw new RuntimeException('文本内容不足');
        return ['text'=>$text,'features'=>$features,'kind'=>'text','raw_html'=>'','title'=>''];
    }
    if ($mime==='application/pdf') {
        $text=url_import_document_text($body,'pdf');
        return ['text'=>$text,'features'=>$features,'kind'=>'pdf','raw_html'=>'','title'=>''];
    }
    if (str_starts_with($mime,'image/') || str_starts_with($mime,'video/')) {
        if ($source['rights_mode']!=='licensed' || trim($source['rights_evidence'])==='') {
            throw new RuntimeException('第三方图片或视频不在自动复用许可范围内，仅登记来源');
        }
        if (str_starts_with($mime,'image/')) {
            $text=url_import_image_vision($body,$mime);
        } else {
            $frames=url_import_frames($body, $mime==='video/webm'?'.webm':'.mp4');
            $text=url_import_vision_notes($frames);
            $features['warnings'][]='视频只评估抽取画面，不包括语音内容';
        }
        return ['text'=>$text,'features'=>$features,'kind'=>$mime,'raw_html'=>'','title'=>''];
    }
    throw new RuntimeException('Agent 暂不支持自动处理此 MIME：'.mb_substr($mime,0,70));
}

/** Licensed sources may carry pre-existing diagrams/tables and images into review. */
function agent_licensed_html(array $review, array $inspection, string $url, array $source): array
{
    $html = agent_summary_html($review,$url);
    $features=$inspection['features'];
    $html .= agent_structured_html($features);
    $media=[];
    if ($inspection['raw_html'] !== '') {
        $doc = new DOMDocument('1.0','UTF-8');
        $prev=libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$inspection['raw_html'].'</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {libxml_clear_errors();libxml_use_internal_errors($prev);}
        $body=import_select_body($doc);
        foreach ($body->getElementsByTagName('img') as $img) {
            if (count($media)>=8) break;
            $src=import_first_image(import_image_candidates($img),$url);
            if ($src==='' || !agent_same_origin($src,$source['origin'])) continue;
            if (!in_array($src,$media,true)) $media[]=$src;
        }
        if ($media) {
            $html.='<h2>已许可的来源配图</h2>';
            foreach ($media as $src) $html.='<figure><img src="'.h($src).'" alt="来源授权素材"></figure>';
        }
    }
    return ['html'=>sanitize_content($html),'media'=>$media];
}

function agent_process_candidate(PDO $pdo, array $source, array $candidate, ?callable $fixture = null): array
{
    $id = (int)$candidate['id'];
    $url = (string)$candidate['url'];
    if (!agent_same_origin($url,$source['origin'])) throw new RuntimeException('候选链接不属于已配置来源');
    $robots=agent_robots_gate($pdo,$source,$url,$fixture);
    if (!$robots['ready']) return ['status'=>'robots','detail'=>$robots['message']];
    if (!$robots['allowed']) {
        $pdo->prepare("UPDATE agent_candidates SET status='rejected',evidence=? WHERE id=?")
            ->execute(['robots.txt 不允许访问',$id]);
        agent_event($pdo,'review','blocked','robots.txt 不允许访问',$source['id'],$id);
        return ['status'=>'blocked'];
    }
    $budget=agent_rate_take($pdo,$source,$url,'page',(int)($robots['delay']??0));
    if (!$budget['ok']) return ['status'=>'cooldown','detail'=>$budget['reason']];
    try {
        $response=url_import_fetch($url, $source['rights_mode']==='licensed'?10485760:2097152,
            $fixture, [], AGENT_UA, true);
        if (!agent_same_origin($response['url'],$source['origin'])) throw new RuntimeException('跨域重定向未获许可');
        $hash=hash('sha256',$response['body']);
        $dupe=$pdo->prepare("SELECT id FROM agent_candidates WHERE content_hash=? AND id<>? AND status IN ('review','draft') LIMIT 1");
        $dupe->execute([$hash,$id]);
        if ($dupe->fetchColumn()) {
            $pdo->prepare("UPDATE agent_candidates SET status='rejected',evidence=?,content_hash=?,checked_at=? WHERE id=?")
                ->execute(['内容与已入库候选重复',$hash,time(),$id]);
            return ['status'=>'duplicate'];
        }
        $inspection=agent_inspect_resource($response,$source);
        $review=agent_ai_evaluate($inspection['text'],$url,(string)$source['industry']);
        if (!$review['approved']) {
            $pdo->prepare("UPDATE agent_candidates SET status='rejected',score=?,content_hash=?,evidence=?,checked_at=? WHERE id=?")
                ->execute([$review['score'],$hash,'AI 判断内容价值不足或缺少可复核依据',time(),$id]);
            return ['status'=>'rejected','score'=>$review['score']];
        }
        $body=agent_summary_html($review,$url);
        $media=[];
        if ($source['rights_mode']==='licensed' && trim($source['rights_evidence'])!=='') {
            $licensed=agent_licensed_html($review,$inspection,$url,$source);
            $body=$licensed['html'];$media=$licensed['media'];
        }
        $stats=['source_kind'=>$inspection['kind'],'formats'=>$inspection['features']['counts'],
            'warnings'=>$inspection['features']['warnings'],'licensed'=>$source['rights_mode']==='licensed'];
        $evidence=['score'=>$review['score'],'language'=>$review['language'],
            'category'=>$review['category'],'concerns'=>$review['concerns'],
            'verification'=>'single_source_not_independently_verified'];
        $pdo->prepare("UPDATE agent_candidates SET status='review',score=?,title=?,body_html=?,content_hash=?,
            evidence=?,format_notes=?,checked_at=? WHERE id=?")
            ->execute([$review['score'],$review['title'],$body,$hash,
                json_encode($evidence,JSON_UNESCAPED_UNICODE),json_encode($stats,JSON_UNESCAPED_UNICODE),time(),$id]);
        foreach ($media as $mediaUrl) {
            $pdo->prepare("INSERT OR IGNORE INTO agent_media(source_id,candidate_id,url,url_hash) VALUES (?,?,?,?)")
                ->execute([$source['id'],$id,$mediaUrl,hash('sha256',$mediaUrl)]);
        }
        agent_event($pdo,'analysis','success','AI评分 '.$review['score'].'；待人工审核', $source['id'],$id);
        return ['status'=>'review','score'=>$review['score'],'media_pending'=>count($media)];
    } catch (Throwable $e) {
        agent_backoff($pdo,$source,$e->getMessage());
        $pdo->prepare("UPDATE agent_candidates SET status='error',evidence=?,checked_at=? WHERE id=?")
            ->execute([mb_substr($e->getMessage(),0,300),time(),$id]);
        agent_event($pdo,'analysis','error',$e->getMessage(),(int)$source['id'],$id);
        return ['status'=>'error','detail'=>$e->getMessage()];
    }
}
