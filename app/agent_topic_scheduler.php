<?php
declare(strict_types=1);

/** Atomic daily provider-call cap. Budget includes failed attempts to avoid loops. */
function agent_topic_budget_take(PDO $pdo,string $kind,int $ideaId,?int $now=null): bool
{
    if (!in_array($kind,['article','image'],true)) return false;
    $now??=time();
    $limit=$kind==='image' ?
        min(8,max(0,(int)setting('agent_topic_image_daily','2'))) :
        min(24,max(1,(int)setting('agent_topic_article_daily','8')));
    if ($limit===0) return false;
    $begun=false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');$begun=true;
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM agent_topic_runs WHERE kind=? AND requested_at>?');
        $stmt->execute([$kind,$now-86400]);
        if ((int)$stmt->fetchColumn()>=$limit) {
            $pdo->exec('COMMIT');$begun=false;
            return false;
        }
        $pdo->prepare('INSERT INTO agent_topic_runs(kind,idea_id,requested_at) VALUES (?,?,?)')
            ->execute([$kind,$ideaId,$now]);
        $pdo->exec('COMMIT');$begun=false;
        return true;
    } catch(Throwable $e) {
        if ($begun) $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Local-AI jobs must never fetch the originating site or media. */
function agent_topic_next(PDO $pdo): ?array
{
    if (setting('agent_creation_mode','topic_only')!=='topic_only') return null;
    // Run a separate quality/risk assessment; it never opens source articles.
    $audit=$pdo->query("SELECT i.* FROM agent_topic_ideas i
        JOIN agent_topic_quality q ON q.idea_id=i.id
        WHERE i.status='review' AND q.status='pending'
        ORDER BY i.id LIMIT 1")->fetch();
    if ($audit && agent_topic_quality_budget($pdo,'review',(int)$audit['id'])) {
        $result=agent_topic_quality_process($pdo,$audit);
        return ['action'=>'quality_review','topic_id'=>(int)$audit['id']]+$result;
    }
    // Settle outstanding optional pictures before issuing new text jobs.
    $pending=$pdo->query("SELECT i.* FROM agent_topic_ideas i
        LEFT JOIN agent_topic_quality q ON q.idea_id=i.id
        WHERE i.status='review' AND i.asset_status='pending'
          AND (q.status IS NULL OR q.status IN ('reviewed','waived'))
        ORDER BY i.id LIMIT 1")->fetch();
    if ($pending && (setting('agent_original_image_enabled','0')!=='1' ||
        agent_topic_budget_take($pdo,'image',(int)$pending['id']))) {
        $result=agent_topic_process_illustration($pdo,$pending);
        return ['action'=>'original_image','topic_id'=>(int)$pending['id']]+$result;
    }
    // Generate optional narration first so that a later MP4 can include it.
    // Expensive media is not generated for articles flagged by quality review.
    $audio=$pdo->query("SELECT i.* FROM agent_topic_ideas i JOIN agent_topic_av av ON av.idea_id=i.id
        LEFT JOIN agent_topic_quality q ON q.idea_id=i.id
        WHERE i.status='review' AND av.audio_status='pending'
          AND (q.status IS NULL OR q.status IN ('reviewed','waived'))
        ORDER BY i.id LIMIT 1")->fetch();
    if ($audio && (setting('agent_original_audio_enabled','0')!=='1' ||
        agent_topic_av_budget($pdo,'audio',(int)$audio['id']))) {
        $result=agent_topic_audio_process($pdo,$audio);
        return ['action'=>'original_audio','topic_id'=>(int)$audio['id']]+$result;
    }
    $video=$pdo->query("SELECT i.* FROM agent_topic_ideas i JOIN agent_topic_av av ON av.idea_id=i.id
        LEFT JOIN agent_topic_quality q ON q.idea_id=i.id
        WHERE i.status='review' AND av.video_status='pending' AND av.audio_status!='pending'
          AND i.asset_status!='pending'
          AND (q.status IS NULL OR q.status IN ('reviewed','waived'))
        ORDER BY i.id LIMIT 1")->fetch();
    if ($video && (setting('agent_original_video_enabled','0')!=='1' ||
        agent_topic_av_budget($pdo,'video',(int)$video['id']))) {
        $result=agent_topic_video_process($pdo,$video);
        return ['action'=>'original_video','topic_id'=>(int)$video['id']]+$result;
    }
    $idea=$pdo->query("SELECT * FROM agent_topic_ideas WHERE status='queued' ORDER BY id LIMIT 1")->fetch();
    if (!$idea) return null;
    if (!agent_topic_budget_take($pdo,'article',(int)$idea['id'])) return null;
    $result=agent_topic_process($pdo,$idea);
    return ['action'=>'write_original','topic_id'=>(int)$idea['id']]+$result;
}

/** Prevent original generation jobs starving site RSS checks and vice versa. */
function agent_topic_schedule(PDO $pdo,int $now,bool $force=false): ?array
{
    if (setting('agent_creation_mode','topic_only')!=='topic_only') return null;
    $last=(int)setting('agent_last_topic_job','0');
    if (!$force && $last > $now-600) return null;
    $out=agent_topic_next($pdo);
    if ($out!==null) set_setting('agent_last_topic_job',(string)$now);
    return $out;
}
