<?php
declare(strict_types=1);

/**
 * Optional follow-up for already reviewed, original tutorials.
 *
 * Automatic verification is restricted to sites the administrator has
 * explicitly enabled. Search-index URLs are candidates, not trusted
 * evidence. They are fetched only after robots + atomic rate reservation.
 * This never changes a quality verdict to "reviewed" or publishes a guide.
 */
function agent_fact_auto_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agent_fact_auto_tasks (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 idea_id INTEGER NOT NULL REFERENCES agent_topic_ideas(id) ON DELETE CASCADE,
 claim_index INTEGER NOT NULL,
 source_id INTEGER NOT NULL REFERENCES agent_sources(id) ON DELETE CASCADE,
 source_url TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'queued'
   CHECK(status IN ('queued','done','skipped','failed')),
 attempts INTEGER NOT NULL DEFAULT 0,
 next_at INTEGER NOT NULL DEFAULT 0,
 last_status TEXT NOT NULL DEFAULT '',
 created_at INTEGER NOT NULL DEFAULT 0,
 UNIQUE(idea_id,claim_index,source_url)
);
CREATE INDEX IF NOT EXISTS agent_fact_auto_due ON agent_fact_auto_tasks(status,next_at);
CREATE TABLE IF NOT EXISTS agent_fact_auto_runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 task_id INTEGER NOT NULL,
 requested_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS agent_fact_auto_runs_day ON agent_fact_auto_runs(requested_at);
CREATE TABLE IF NOT EXISTS agent_fact_auto_plans (
 idea_id INTEGER PRIMARY KEY REFERENCES agent_topic_ideas(id) ON DELETE CASCADE,
 quality_checked_at INTEGER NOT NULL DEFAULT 0,
 scanned_at INTEGER NOT NULL,
 candidate_count INTEGER NOT NULL DEFAULT 0
);
SQL);
    $columns=$pdo->query('PRAGMA table_info(agent_fact_auto_tasks)')->fetchAll();
    $names=array_column($columns,'name');
    if(!in_array('quality_checked_at',$names,true)) {
        $pdo->exec('ALTER TABLE agent_fact_auto_tasks ADD COLUMN quality_checked_at INTEGER NOT NULL DEFAULT 0');
    }
}

/**
 * Only existing search hints from the SECOND, separate editorial pass.
 * Independent domains + exact registered origins, never visit an unknown
 * site even if the model marks it as "authoritative".
 */
function agent_fact_auto_plan(PDO $pdo,int $ideaId): int
{
    if (setting('agent_fact_auto_enabled','0')!=='1') return 0;
    $q=$pdo->prepare("SELECT i.status AS idea_status,q.status AS review_status,
        q.issues_json,q.evidence_json,q.checked_at AS review_checked_at FROM agent_topic_ideas i
        JOIN agent_topic_quality q ON q.idea_id=i.id WHERE i.id=?");
    $q->execute([$ideaId]);$record=$q->fetch();
    if (!$record || $record['idea_status']!=='review' ||
        $record['review_status']!=='needs_review') return 0;
    $checked=(int)$record['review_checked_at'];
    $existingPlan=$pdo->prepare('SELECT quality_checked_at,scanned_at FROM agent_fact_auto_plans WHERE idea_id=?');
    $existingPlan->execute([$ideaId]);$oldPlan=$existingPlan->fetch();
    if($oldPlan && (int)$oldPlan['quality_checked_at']===$checked &&
        (int)$oldPlan['scanned_at']>time()-86400) return 0;
    // Invalidate tasks attached to a previous, revised claim list.
    $pdo->prepare("UPDATE agent_fact_auto_tasks SET status='skipped',last_status='outdated_review'
        WHERE idea_id=? AND quality_checked_at!=? AND status='queued'")
        ->execute([$ideaId,$checked]);
    $claims=json_decode((string)$record['issues_json'],true)['claims']??[];
    $evidence=json_decode((string)$record['evidence_json'],true);
    if(!is_array($claims) || !is_array($evidence)) return 0;
    $sources=$pdo->query('SELECT id,origin FROM agent_sources WHERE enabled=1 ORDER BY id LIMIT 100')->fetchAll();
    // Even when sources are empty, persist the scan to avoid spinning every tick.
    if (!$sources) {
        $pdo->prepare('INSERT INTO agent_fact_auto_plans(idea_id,quality_checked_at,scanned_at,candidate_count) VALUES(?,?,?,0) ON CONFLICT(idea_id) DO UPDATE SET quality_checked_at=excluded.quality_checked_at,scanned_at=excluded.scanned_at,candidate_count=0')->execute([$ideaId,$checked,time()]);
        return 0;
    }
    $existing=$pdo->prepare('SELECT COUNT(*) FROM agent_fact_auto_tasks WHERE idea_id=?');
    $existing->execute([$ideaId]);
    $remaining=max(0,4-(int)$existing->fetchColumn());
    if(!$remaining) return 0;
    $insert=$pdo->prepare("INSERT OR IGNORE INTO agent_fact_auto_tasks
        (idea_id,claim_index,source_id,source_url,created_at,quality_checked_at) VALUES(?,?,?,?,?,?)");
    $count=0;
    foreach(array_slice($claims,0,8) as $idx=>$claim) {
        // Search-snippet corroboration is NOT a reason to skip primary-source checks.
        if(!is_array($claim)) continue;
        $indexes=is_array($claim['source_indexes']??null)?$claim['source_indexes']:[];
        // No model-supplied URLs may be invented here; only indexed results
        // from our stored, separately fetched search API evidence.
        if(!$indexes) $indexes=range(1,min(5,count($evidence)));
        $domainUsed=[];
        foreach(array_slice($indexes,0,5) as $index) {
            if(!is_int($index) || $index<1 || !isset($evidence[$index-1])) continue;
            $candidate=(string)($evidence[$index-1]['url']??'');
            try {$candidate=import_page_url($candidate);}catch(Throwable $e){continue;}
            $host=(string)parse_url($candidate,PHP_URL_HOST);
            if($host==='' || isset($domainUsed[$host])) continue;
            foreach($sources as $source) {
                if(!agent_same_origin($candidate,(string)$source['origin'])) continue;
                $insert->execute([$ideaId,(int)$idx,(int)$source['id'],$candidate,time(),$checked]);
                if($insert->rowCount()) {
                    $domainUsed[$host]=true;
                    $count++;$remaining--;
                }
                break;
            }
            if(!$remaining || count($domainUsed)>=2) break;
        }
        if(!$remaining) break;
    }
    $pdo->prepare('INSERT INTO agent_fact_auto_plans(idea_id,quality_checked_at,scanned_at,candidate_count)
        VALUES(?,?,?,?) ON CONFLICT(idea_id) DO UPDATE SET
        quality_checked_at=excluded.quality_checked_at,scanned_at=excluded.scanned_at,
        candidate_count=excluded.candidate_count')->execute([$ideaId,$checked,time(),$count]);
    return $count;
}

/** A reservation for attempts, not a grant to bypass site access policy. */
function agent_fact_auto_budget(PDO $pdo,int $taskId,?int $now=null): bool
{
    $now??=time();
    $limit=min(8,max(0,(int)setting('agent_fact_auto_daily','2')));
    if(!$limit) return false;
    $open=false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');$open=true;
        $q=$pdo->prepare('SELECT COUNT(*) FROM agent_fact_auto_runs WHERE requested_at>?');
        $q->execute([$now-86400]);
        if((int)$q->fetchColumn()>=$limit) {
            $pdo->exec('COMMIT');$open=false;return false;
        }
        $pdo->prepare('INSERT INTO agent_fact_auto_runs(task_id,requested_at) VALUES(?,?)')
            ->execute([$taskId,$now]);
        $pdo->exec('COMMIT');$open=false;return true;
    } catch(Throwable $e) {
        if($open && $pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function agent_fact_auto_step(PDO $pdo,?callable $http=null,?callable $ai=null,?int $now=null): ?array
{
    if(setting('agent_fact_auto_enabled','0')!=='1') return null;
    $now??=time();
    // Populate at most one new article's candidates per tick. This costs
    // no network calls or model tokens.
    $unplanned=$pdo->query("SELECT i.id FROM agent_topic_ideas i
       JOIN agent_topic_quality q ON q.idea_id=i.id
       WHERE i.status='review' AND q.status='needs_review'
       AND NOT EXISTS(SELECT 1 FROM agent_fact_auto_plans p WHERE p.idea_id=i.id
           AND p.quality_checked_at=q.checked_at AND p.scanned_at>strftime('%s','now')-86400)
       ORDER BY i.id ASC LIMIT 1")->fetchColumn();
    if($unplanned) agent_fact_auto_plan($pdo,(int)$unplanned);
    $q=$pdo->prepare("SELECT t.* FROM agent_fact_auto_tasks t
        JOIN agent_topic_ideas i ON i.id=t.idea_id
        JOIN agent_topic_quality q ON q.idea_id=i.id
        WHERE t.status='queued' AND t.next_at<=?
          AND i.status='review' AND q.status='needs_review'
          AND t.quality_checked_at=q.checked_at
        ORDER BY t.next_at,t.id LIMIT 1");
    $q->execute([$now]);$task=$q->fetch();
    if(!$task || !agent_fact_auto_budget($pdo,(int)$task['id'],$now)) return null;
    $out=[];
    try {
        $out=agent_fact_document_add($pdo,(int)$task['idea_id'],(int)$task['claim_index'],
            (int)$task['source_id'],(string)$task['source_url'],$http,$ai);
        $status=(string)($out['status']??'');
        $terminal=in_array($status,['supports','conflicts','unclear','error'],true);
        $failed=$status==='blocked';
        $next=$now+7200;
        if(!$terminal && !$failed) {
            // The source may have a stricter crawl-delay/cooldown after robots.
            $source=$pdo->prepare('SELECT next_fetch,blocked_until FROM agent_sources WHERE id=?');
            $source->execute([$task['source_id']]);
            $sourceRow=$source->fetch();
            if($sourceRow) $next=max($next,(int)$sourceRow['next_fetch'],(int)$sourceRow['blocked_until']);
        }
        $attempt=(int)$task['attempts']+1;
        $newStatus=$terminal?'done':($failed || $attempt>=4?'skipped':'queued');
        $pdo->prepare('UPDATE agent_fact_auto_tasks SET status=?,attempts=?,next_at=?,last_status=? WHERE id=?')
            ->execute([$newStatus,$attempt,$next,mb_substr($status,0,100),(int)$task['id']]);
        // A contradictory primary source is a reason for MORE human review,
        // never a reason to silently auto-fix the original draft.
        if($status==='conflicts') {
            $pdo->prepare("UPDATE agent_topic_quality SET risk='high',status='needs_review',
                model_notes='自动原文核查出现矛盾；须人工复核' WHERE idea_id=? AND status='needs_review'")
                ->execute([(int)$task['idea_id']]);
        }
        return ['action'=>'fact_document','status'=>$status,'task_id'=>(int)$task['id'],
            'attempts'=>$attempt,'next_at'=>$next];
    } catch(Throwable $e) {
        $attempt=(int)$task['attempts']+1;
        $pdo->prepare('UPDATE agent_fact_auto_tasks SET attempts=?,status=?,next_at=?,last_status=? WHERE id=?')
            ->execute([$attempt,$attempt>=3?'failed':'queued',$now+21600,
                mb_substr(get_class($e),0,100),(int)$task['id']]);
        agent_event($pdo,'fact_auto','error','原文核验失败：'.get_class($e));
        return ['action'=>'fact_document','status'=>'error','task_id'=>(int)$task['id']];
    }
}

function agent_fact_auto_for_idea(PDO $pdo,int $ideaId): array
{
    $q=$pdo->prepare('SELECT id,claim_index,source_url,status,attempts,next_at,last_status
        FROM agent_fact_auto_tasks WHERE idea_id=? ORDER BY id DESC LIMIT 8');
    $q->execute([$ideaId]);
    return $q->fetchAll();
}
