<?php
declare(strict_types=1);

/**
 * Exactly one site operation per scheduler tick. No parallel jobs, random URL
 * wandering, proxy rotation, search-engine scraping, or bypass attempts.
 */
function agent_tick(PDO $pdo, ?callable $fixture = null, ?int $now = null): array
{
    $now ??= time();
    if (setting('agent_enabled','0')!=='1') return ['status'=>'disabled'];
    $sources=$pdo->prepare('SELECT * FROM agent_sources WHERE enabled=1 AND blocked_until<=? AND next_fetch<=? ORDER BY next_fetch ASC,id ASC LIMIT 25');
    $sources->execute([$now,$now]);
    foreach ($sources->fetchAll() as $source) {
        $media=$pdo->prepare("SELECT * FROM agent_media WHERE source_id=? AND status='queued' ORDER BY id LIMIT 1");
        $media->execute([$source['id']]);
        $pending=$media->fetch();
        if ($pending) {
            $result=agent_process_media($pdo,$source,$pending,$fixture);
            if (in_array($result['status'],['cooldown','robots'],true)) {
                $pdo->prepare('UPDATE agent_sources SET next_fetch=MAX(next_fetch,?) WHERE id=?')
                    ->execute([$now+300,$source['id']]);
            }
            return ['source'=>$source['origin'],'action'=>'media']+$result;
        }
        $candidate=$pdo->prepare("SELECT * FROM agent_candidates WHERE source_id=? AND status='queued' AND next_check<=? ORDER BY id LIMIT 1");
        $candidate->execute([$source['id'],$now]);
        $job=$candidate->fetch();
        if ($job) {
            $result=agent_process_candidate($pdo,$source,$job,$fixture);
            if ($result['status']==='cooldown') {
                $pdo->prepare('UPDATE agent_candidates SET next_check=? WHERE id=?')
                    ->execute([$now+3600,$job['id']]);
                $pdo->prepare('UPDATE agent_sources SET next_fetch=MAX(next_fetch,?) WHERE id=?')
                    ->execute([$now+300,$source['id']]);
            }
            return ['source'=>$source['origin'],'action'=>'evaluate']+$result;
        }
        $feedQuery=$pdo->prepare("SELECT * FROM agent_feeds WHERE source_id=? AND status='queued' ORDER BY id LIMIT 1");
        $feedQuery->execute([$source['id']]);
        $nested=$feedQuery->fetch();
        $result=agent_discover($pdo,$source,$fixture,$nested ? $nested['url'] : '');
        if ($nested && in_array($result['status'],['discovered','blocked','error'],true)) {
            $pdo->prepare("UPDATE agent_feeds SET status=? WHERE id=?")
                ->execute([$result['status']==='discovered'?'done':'skipped',$nested['id']]);
        }
        if ($result['status']==='cooldown') {
            $pdo->prepare('UPDATE agent_sources SET next_fetch=MAX(next_fetch,?) WHERE id=?')
                ->execute([$now+3600,$source['id']]);
        }
        return ['source'=>$source['origin'],'action'=>'discover']+$result;
    }
    if (setting('agent_auto_source_enabled','0')==='1') {
        $research=agent_research_tick($pdo,null,null,$now);
        return ['action'=>'source_research']+$research;
    }
    return ['status'=>'idle','message'=>'所有来源均在冷却期或未启用'];
}

/** Only human approval can create a new private guide draft. Never publish. */
function agent_approve_candidate(PDO $pdo, int $id): int
{
    $pdo->beginTransaction();
    try {
        $query=$pdo->prepare("SELECT c.*,s.rights_mode,s.rights_evidence FROM agent_candidates c
            JOIN agent_sources s ON s.id=c.source_id WHERE c.id=?");
        $query->execute([$id]);
        $c=$query->fetch();
        if (!$c || $c['status']!=='review' || $c['guide_id']!==null) {
            throw new RuntimeException('该候选不是可创建草稿的待审核内容');
        }
        $sourceOriginStmt=$pdo->prepare('SELECT origin FROM agent_sources WHERE id=?');
        $sourceOriginStmt->execute([$c['source_id']]);
        $sourceOrigin=(string)$sourceOriginStmt->fetchColumn();
        if (!agent_same_origin($c['url'],$sourceOrigin)) throw new RuntimeException('来源 URL 不符合该站点授权范围');
        $format=json_decode((string)$c['format_notes'],true) ?: [];
        if (!empty($format['licensed']) && ($c['rights_mode']!=='licensed' || trim($c['rights_evidence'])==='')) {
            throw new RuntimeException('原来源转载许可已撤销，不能创建包含原始结构或媒体的草稿');
        }
        if (mb_strlen(strip_tags($c['body_html']))<50) throw new RuntimeException('候选正文内容不足');
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM agent_media WHERE candidate_id=? AND status='queued'");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn()>0) throw new RuntimeException('授权配图仍在等待低频转存，完成或标记失败后再创建草稿');
        $slug=make_slug($c['title']).'-'.bin2hex(random_bytes(4));
        $meta=json_decode((string)$c['evidence'],true) ?: [];
        $summary=mb_substr('AI 初筛评分 '.$c['score'].'/100；单一来源尚待事实核实。'.((string)($meta['category']??'')),0,450);
        $insert=$pdo->prepare("INSERT INTO guides(title,slug,summary,body_html,status,tags) VALUES (?,?,?,?,'draft',?)");
        $insert->execute([$c['title'],$slug,$summary,sanitize_content($c['body_html']),mb_substr((string)($meta['category']??''),0,80)]);
        $guideId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE agent_candidates SET status='draft',guide_id=? WHERE id=?")
            ->execute([$guideId,$id]);
        $pdo->commit();
        agent_event($pdo,'approve','success','管理员已创建未发布草稿',$c['source_id'],$id);
        return $guideId;
    } catch (Throwable $e) {
        $pdo->rollBack();throw $e;
    }
}
