<?php
declare(strict_types=1);

/** Local-AI jobs must never fetch the originating site or media. */
function agent_topic_next(PDO $pdo): ?array
{
    if (setting('agent_creation_mode','topic_only')!=='topic_only') return null;
    // Settle outstanding optional pictures before issuing new text jobs.
    $pending=$pdo->query("SELECT * FROM agent_topic_ideas WHERE status='review' AND asset_status='pending' ORDER BY id LIMIT 1")->fetch();
    if ($pending) {
        $result=agent_topic_process_illustration($pdo,$pending);
        return ['action'=>'original_image','topic_id'=>(int)$pending['id']]+$result;
    }
    $idea=$pdo->query("SELECT * FROM agent_topic_ideas WHERE status='queued' ORDER BY id LIMIT 1")->fetch();
    if (!$idea) return null;
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
