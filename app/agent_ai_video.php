<?php
declare(strict_types=1);

/**
 * Provider-neutral implementation of the asynchronous POST /videos,
 * GET /videos/{id}, GET /videos/{id}/content interface.
 * Do NOT use a default Sora model: OpenAI's Videos API shut down in 2026.
 * Operators must configure a currently working compatible provider.
 *
 * All network calls target a fixed API origin with a strictly validated
 * provider-owned ID. Never fetch arbitrary model-returned URLs.
 */
function agent_ai_video_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agent_ai_video_jobs (
 idea_id INTEGER PRIMARY KEY REFERENCES agent_topic_ideas(id) ON DELETE CASCADE,
 status TEXT NOT NULL DEFAULT 'off' CHECK(status IN
   ('off','queued','running','ready','complete','failed','skipped')),
 remote_id TEXT NOT NULL DEFAULT '',
 provider_base TEXT NOT NULL DEFAULT '',
 model TEXT NOT NULL DEFAULT '',
 prompt TEXT NOT NULL DEFAULT '',
 submitted_at INTEGER NOT NULL DEFAULT 0,
 updated_at INTEGER NOT NULL DEFAULT 0,
 next_check INTEGER NOT NULL DEFAULT 0,
 checks INTEGER NOT NULL DEFAULT 0,
 mp4_url TEXT NOT NULL DEFAULT '',
 error TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS agent_ai_video_pending ON agent_ai_video_jobs(status,next_check);
CREATE TABLE IF NOT EXISTS agent_ai_video_budget (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 idea_id INTEGER NOT NULL,
 created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS agent_ai_video_budget_recent ON agent_ai_video_budget(created_at);
CREATE TABLE IF NOT EXISTS agent_ai_video_options (
 idea_id INTEGER PRIMARY KEY REFERENCES agent_topic_ideas(id) ON DELETE CASCADE,
 seconds INTEGER NOT NULL DEFAULT 4,
 size TEXT NOT NULL DEFAULT '1280x720',
 max_mb INTEGER NOT NULL DEFAULT 18
);
SQL);
    $columns=$pdo->query('PRAGMA table_info(agent_ai_video_jobs)')->fetchAll();
    $columns=array_column($columns,'name');
    if(!in_array('lease_token',$columns,true)) {
        $pdo->exec("ALTER TABLE agent_ai_video_jobs ADD COLUMN lease_token TEXT NOT NULL DEFAULT ''");
    }
    if(!in_array('lease_until',$columns,true)) {
        $pdo->exec("ALTER TABLE agent_ai_video_jobs ADD COLUMN lease_until INTEGER NOT NULL DEFAULT 0");
    }
}

function agent_ai_video_model(): string
{
    $model=trim(setting('agent_ai_video_model',''));
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:+-]{0,99}$/D',$model)) {
        throw new RuntimeException('请配置当前供应商真实可用的视频模型 ID');
    }
    return $model;
}

function agent_ai_video_config(): array
{
    $base=setting('agent_ai_video_base','');
    $cipher=setting('agent_ai_video_key_cipher','');
    if ($base==='' || $cipher==='') throw new RuntimeException('请先配置独立的视频供应商 URL 和 API Key');
    $base=ai_base_url($base);
    $model=agent_ai_video_model();
    return ['base'=>$base,'cipher'=>$cipher,'model'=>$model,
        'format'=>setting('agent_ai_video_request_format','multipart')==='json'?'json':'multipart'];
}

function agent_ai_video_prompt(array $idea): string
{
    // Never send discovery source URLs or any scraped article body.
    $title=trim(mb_substr(strip_tags((string)$idea['title']),0,140));
    if (mb_strlen($title)<6) throw new RuntimeException('视频选题标题过短');
    $doc=new DOMDocument('1.0','UTF-8');
    $old=libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.
            sanitize_content((string)$idea['html']).'</body></html>',
            LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    } finally {libxml_clear_errors();libxml_use_internal_errors($old);}
    $parts=[];
    $xp=new DOMXPath($doc);
    foreach($xp->query('//h3 | //li')?:[] as $node) {
        $value=trim($node->textContent);
        if ($value!=='' && mb_strlen($value)>=4) $parts[]=mb_substr($value,0,115);
        if (count($parts)>=7) break;
    }
    if (!$parts) throw new RuntimeException('原创教程缺少可用于视频的操作要点');
    return mb_substr(
        "Generate a fully original short educational animated demonstration, not real device footage or an exact software screenshot. ".
        "Use clean symbolic visuals and clear step-by-step movement with no logos, no copyrighted characters, no fabricated measurements or real credentials. ".
        "Do not copy any third-party video or image. Tutorial topic: ".$title.
        ". Independently authored steps: ".implode('; ',$parts).
        ". Keep actions safe and educational. No unrelated text overlays.",0,1700);
}

function agent_ai_video_job_validate(array $response): array
{
    $id=$response['id']??'';
    $status=$response['status']??'';
    if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{3,128}$/D',$id)) {
        throw new RuntimeException('视频供应商任务 ID 不符合安全规则');
    }
    if (!in_array($status,['queued','in_progress','completed','failed','cancelled','canceled'],true)) {
        throw new RuntimeException('视频供应商未返回可识别的任务状态');
    }
    return ['id'=>$id,'status'=>$status];
}

/**
 * Action is start|check|download.
 * Fixture signature: function(string $action,array $payload): array|string.
 * A download must return raw MP4 bytes, never a provider-supplied URL.
 */
function agent_ai_video_transport(string $action,array $payload,?callable $fixture=null): array|string
{
    if ($fixture) return $fixture($action,$payload);
    $config=agent_ai_video_config();
    if (!extension_loaded('curl')) throw new RuntimeException('PHP 未启用 curl');
    $id=(string)($payload['id']??'');
    if ($action!=='start' && !preg_match('/^[a-zA-Z0-9_-]{3,128}$/D',$id)) {
        throw new RuntimeException('视频任务 ID 不合法');
    }
    $url=$config['base'].($action==='start'?'/videos':'/videos/'.$id.($action==='download'?'/content':''));
    $key=ai_decrypt($config['cipher']);
    $response='';$overflow=false;
    $limit=$action==='download'?18*1024*1024+1:65536;
    $headers=['Authorization: Bearer '.$key,'Accept: '.($action==='download'?'video/mp4':'application/json')];
    $ch=curl_init($url);
    $opts=[
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_CONNECTTIMEOUT=>9,
        CURLOPT_TIMEOUT=>$action==='download'?120:25,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        CURLOPT_PROXY=>'',
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$response,&$overflow,$limit):int{
            if(strlen($response)+strlen($chunk)>$limit){$overflow=true;return 0;}
            $response.=$chunk;return strlen($chunk);
        },
    ];
    if ($action==='start') {
        $opts[CURLOPT_POST]=true;
        $allowedSizes=['1280x720','720x1280','1920x1080'];
        $size=(string)($payload['size']??'1280x720');
        $seconds=(int)($payload['seconds']??4);
        if(!in_array($size,$allowedSizes,true) || $seconds<4 || $seconds>120) {
            throw new RuntimeException('视频尺寸/时长超出受控模型参数范围');
        }
        $fields=['model'=>$config['model'],'prompt'=>(string)$payload['prompt'],
            'size'=>$size,'seconds'=>(string)$seconds];
        if($config['format']==='json') {
            $opts[CURLOPT_POSTFIELDS]=json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $headers[]='Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER]=$headers;
        } else {
            $opts[CURLOPT_POSTFIELDS]=$fields;
        }
    }
    curl_setopt_array($ch,$opts);
    $ok=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if($overflow || $ok===false || $status<200 || $status>=300) {
        // Never log a provider body, token, or arbitrary destination.
        throw new RuntimeException('视频供应商 '.$action.' 调用失败，HTTP '.$status);
    }
    if($action==='download') {
        if (!str_starts_with(strtolower(trim(explode(';',$mime)[0])),'video/mp4') &&
            !in_array(strtolower(trim(explode(';',$mime)[0])),['application/octet-stream',''],true)) {
            throw new RuntimeException('模型返回的内容不是 MP4 类型');
        }
        return $response;
    }
    $json=json_decode($response,true);
    if (!is_array($json)) throw new RuntimeException('视频服务未返回 JSON');
    return $json;
}

function agent_ai_video_daily_budget(PDO $pdo,int $id,?int $now=null): bool
{
    $now??=time();
    $limit=min(4,max(0,(int)setting('agent_ai_video_daily','1')));
    if (!$limit) return false;
    $transaction=false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');$transaction=true;
        $q=$pdo->prepare('SELECT COUNT(*) FROM agent_ai_video_budget WHERE created_at>?');
        $q->execute([$now-86400]);
        if((int)$q->fetchColumn()>=$limit){$pdo->exec('COMMIT');return false;}
        $pdo->prepare('INSERT INTO agent_ai_video_budget(idea_id,created_at) VALUES(?,?)')
            ->execute([$id,$now]);
        $pdo->exec('COMMIT');return true;
    }catch(Throwable $e){
        if($transaction && $pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function agent_ai_video_enqueue(PDO $pdo,int $id): void
{
    $enabled=setting('agent_ai_video_enabled','0')==='1';
    $pdo->prepare("INSERT INTO agent_ai_video_jobs(idea_id,status) VALUES (?,?)
        ON CONFLICT(idea_id) DO NOTHING")->execute([$id,$enabled?'queued':'off']);
    $seconds=(int)setting('agent_ai_video_seconds','4');
    $size=setting('agent_ai_video_size','1280x720');
    $maxMb=(int)setting('agent_ai_video_max_mb','18');
    if($seconds<4||$seconds>120||!in_array($size,['1280x720','720x1280','1920x1080'],true)||
        $maxMb<1||$maxMb>128) throw new RuntimeException('视频任务大小、分辨率或时长配置不合法');
    $pdo->prepare('INSERT OR IGNORE INTO agent_ai_video_options(idea_id,seconds,size,max_mb) VALUES(?,?,?,?)')
        ->execute([$id,$seconds,$size,$maxMb]);
}

function agent_ai_video_options(PDO $pdo,int $id): array
{
    $q=$pdo->prepare('SELECT seconds,size,max_mb FROM agent_ai_video_options WHERE idea_id=?');
    $q->execute([$id]);$options=$q->fetch();
    return $options ?: ['seconds'=>4,'size'=>'1280x720','max_mb'=>18];
}

/**
 * Exclusive per-video durable lease. The status predicate prevents callers
 * with stale queue snapshots from submitting a second paid request. Each
 * attempt uses a separate random token, so only its owner may release it.
 * Crash recovery waits 15 minutes (longer than the bounded download).
 */
function agent_ai_video_claim(PDO $pdo,int $ideaId,string $status,int $now): ?string
{
    if(!in_array($status,['queued','running','ready'],true)) return null;
    $token=bin2hex(random_bytes(16));
    $q=$pdo->prepare("UPDATE agent_ai_video_jobs SET lease_token=?,lease_until=?
        WHERE idea_id=? AND status=? AND lease_until<=?");
    $q->execute([$token,$now+900,$ideaId,$status,$now]);
    return $q->rowCount()===1?$token:null;
}

function agent_ai_video_release(PDO $pdo,int $ideaId,string $token): void
{
    $q=$pdo->prepare("UPDATE agent_ai_video_jobs SET lease_token='',lease_until=0
        WHERE idea_id=? AND lease_token=?");
    $q->execute([$ideaId,$token]);
}

function agent_ai_video_process(PDO $pdo,array $job,array $idea,?callable $fixture=null,?int $now=null): array
{
    $now??=time();$id=(int)$idea['id'];$state=(string)$job['status'];
    if($state==='off' || in_array($state,['complete','failed','skipped'],true) ||
        $idea['status']!=='review') return ['status'=>'idle'];
    // A second cron/HTTP worker must not perform this job concurrently.
    $lease=agent_ai_video_claim($pdo,$id,$state,$now);
    if($lease===null) return ['status'=>'busy'];
    try {
        // Another worker may have fetched the same status while the original
        // task was still obtaining its remote ID. After acquiring the lease,
        // NEVER trust the stale passed-in snapshot for billing or polling.
        $current=$pdo->prepare('SELECT * FROM agent_ai_video_jobs WHERE idea_id=? AND lease_token=?');
        $current->execute([$id,$lease]);
        $latest=$current->fetch();
        if(!$latest) throw new RuntimeException('视频任务执行权状态不一致');
        $job=$latest;
        $state=(string)$job['status'];
        $config=agent_ai_video_config();
        if($state==='queued') {
            if(!agent_ai_video_daily_budget($pdo,$id,$now)) return ['status'=>'budget_exhausted'];
            $prompt=agent_ai_video_prompt($idea);
            // Record intent BEFORE the network call. On an ambiguous timeout
            // never automatically resubmit a potentially billed job.
            $pdo->prepare("UPDATE agent_ai_video_jobs SET status='running',
                provider_base=?,model=?,prompt=?,submitted_at=?,updated_at=?,next_check=?,checks=0
                WHERE idea_id=? AND status='queued'")
                ->execute([$config['base'],$config['model'],$prompt,$now,$now,$now+90,$id]);
            $request=agent_ai_video_options($pdo,$id);
            $result=agent_ai_video_transport('start',['prompt'=>$prompt,
                'seconds'=>(int)$request['seconds'],'size'=>(string)$request['size']],$fixture);
            if(!is_array($result)) throw new RuntimeException('视频创建响应不是 JSON');
            $remote=agent_ai_video_job_validate($result);
            $newStatus=$remote['status']==='completed'?'ready':(
                in_array($remote['status'],['failed','cancelled','canceled'],true)?'failed':'running');
            $pdo->prepare('UPDATE agent_ai_video_jobs SET remote_id=?,status=?,updated_at=?,next_check=? WHERE idea_id=?')
                ->execute([$remote['id'],$newStatus,$now,$now+90,$id]);
            return ['status'=>$newStatus,'remote_id'=>$remote['id']];
        }
        if($job['provider_base']!==$config['base']) {
            throw new RuntimeException('视频供应商地址已变更，请人工处理旧任务');
        }
        $remoteId=(string)$job['remote_id'];
        if(!preg_match('/^[a-zA-Z0-9_-]{3,128}$/D',$remoteId)) {
            throw new RuntimeException('远端视频任务 ID 丢失；可能已扣费，请人工核对后处理');
        }
        if($now-(int)$job['submitted_at']>86400 || (int)$job['checks']>=120) {
            throw new RuntimeException('视频生成任务超过 24 小时或最大检查次数');
        }
        if($state==='running') {
            $response=agent_ai_video_transport('check',['id'=>$remoteId],$fixture);
            if(!is_array($response)) throw new RuntimeException('视频状态响应不是 JSON');
            $new=agent_ai_video_job_validate($response);
            if($new['id']!==$remoteId) throw new RuntimeException('视频供应商返回了不一致的任务 ID');
            $state=$new['status']==='completed'?'ready':(
                in_array($new['status'],['failed','cancelled','canceled'],true)?'failed':'running');
            $pdo->prepare('UPDATE agent_ai_video_jobs SET status=?,checks=checks+1,updated_at=?,next_check=?
                WHERE idea_id=?')->execute([$state,$now,$now+120,$id]);
            return ['status'=>$state];
        }
        if($state==='ready') {
            $url=(string)($job['mp4_url']??'');
            if($url==='') {
                $options=agent_ai_video_options($pdo,$id);
                $maxMb=(int)$options['max_mb'];
                $temp=agent_ai_video_receive_mp4($remoteId,$maxMb,$fixture);
                try {
                    $url=agent_ai_video_register_mp4($pdo,$temp,$maxMb);
                } finally {@unlink($temp);}
                // Save the local archive pointer FIRST. A later HTML update
                // failure must never trigger another paid download or orphan
                // the already stored local MP4.
                $pdo->prepare("UPDATE agent_ai_video_jobs SET mp4_url=?,updated_at=? WHERE idea_id=? AND status='ready'")
                    ->execute([$url,$now,$id]);
            }
            $asset=$pdo->prepare("SELECT * FROM media_assets WHERE url=? AND kind='file' AND mime='video/mp4'");
            $asset->execute([$url]);
            $found=$asset->fetch();
            if (!$found || !media_file_path($found)) {
                throw new RuntimeException('已归档的 MP4 不存在，必须由管理员检查媒体库');
            }
            agent_topic_av_append($pdo,$id,'video',$url,'generated');
            $pdo->prepare("UPDATE agent_ai_video_jobs SET status='complete',updated_at=? WHERE idea_id=?")
                ->execute([$now,$id]);
            return ['status'=>'complete','url'=>$url];
        }
        return ['status'=>'idle'];
    }catch(Throwable $e) {
        $pdo->prepare("UPDATE agent_ai_video_jobs SET status='failed',error=?,updated_at=?
            WHERE idea_id=? AND lease_token=?")
            ->execute([mb_substr($e->getMessage(),0,250),$now,$id,$lease]);
        agent_event($pdo,'original_video_provider','error','视频供应商处理失败：'.get_class($e));
        return ['status'=>'failed','message'=>$e->getMessage()];
    } finally {
        // Safe even if a newer owner took over after an expired lease.
        agent_ai_video_release($pdo,$id,$lease);
    }
}
