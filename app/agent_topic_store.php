<?php
declare(strict_types=1);

/**
 * Topic-only creation is isolated from full-source imports. Internal
 * provenance remains in SQLite for dedupe, audits and takedown requests,
 * but is never automatically copied into the public article.
 */
function agent_topic_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agent_topic_ideas (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 topic_hash TEXT NOT NULL UNIQUE,
 raw_title TEXT NOT NULL,
 question TEXT NOT NULL DEFAULT '',
 industry TEXT NOT NULL DEFAULT '',
 language TEXT NOT NULL DEFAULT '',
 source_url TEXT NOT NULL DEFAULT '',
 source_method TEXT NOT NULL CHECK(source_method IN ('search_api','search_question','feed_title','feed_question','manual')),
 source_id INTEGER REFERENCES agent_sources(id) ON DELETE SET NULL,
 status TEXT NOT NULL DEFAULT 'queued'
   CHECK(status IN ('queued','review','rejected','error','draft')),
 title TEXT NOT NULL DEFAULT '',
 score INTEGER NOT NULL DEFAULT 0,
 html TEXT NOT NULL DEFAULT '',
 content_hash TEXT NOT NULL DEFAULT '',
 image_prompt TEXT NOT NULL DEFAULT '',
 image_url TEXT NOT NULL DEFAULT '',
 summary TEXT NOT NULL DEFAULT '',
 review_notes TEXT NOT NULL DEFAULT '',
 asset_status TEXT NOT NULL DEFAULT 'none',
 guide_id INTEGER REFERENCES guides(id) ON DELETE SET NULL,
 checked_at INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS agent_topic_queue ON agent_topic_ideas(status,id);
CREATE INDEX IF NOT EXISTS agent_topic_guide ON agent_topic_ideas(guide_id);
CREATE TABLE IF NOT EXISTS agent_topic_runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 kind TEXT NOT NULL CHECK(kind IN ('article','image')),
 idea_id INTEGER,
 requested_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS agent_topic_runs_recent ON agent_topic_runs(kind,requested_at);
SQL);
    agent_topic_quality_tables($pdo);
    agent_topic_av_tables($pdo);
}

function agent_topic_clean_title(string $raw): string
{
    $raw=html_entity_decode(strip_tags($raw),ENT_QUOTES | ENT_HTML5,'UTF-8');
    $raw=preg_replace('/[\x00-\x1f\x7f-\x9f]+/u',' ',$raw) ?? '';
    $raw=trim(preg_replace('/\s+/u',' ',$raw) ?? '');
    // Remove only recognisable site-name suffixes after a complete heading.
    $raw=preg_replace('/\s+(?:\||·|—)\s+[^|·—]{2,35}$/u','',$raw) ?? $raw;
    $raw=mb_substr($raw,0,180);
    if (mb_strlen($raw)<4) throw new InvalidArgumentException('标题太短，无法提取有效选题');
    if (preg_match('~^(?:首页|Home|About Us|隐私政策|登录|注册|联系我们|Cookie Policy|Sign In)$~iu',$raw)) {
        throw new InvalidArgumentException('无实际知识选题的导航标题');
    }
    if (preg_match('/(?:ignore previous instructions|system prompt|developer message)/i',$raw)) {
        throw new InvalidArgumentException('标题包含不可信的指令文本');
    }
    return $raw;
}

function agent_topic_enqueue(PDO $pdo, string $title, string $sourceUrl, string $method,
    string $industry = '', string $language = '', ?int $sourceId = null): bool
{
    if (!in_array($method,['search_api','search_question','feed_title','feed_question','manual'],true)) return false;
    if ($method!=='manual' && trim($sourceUrl)==='') return false;
    try {
        $title=agent_topic_clean_title($title);
        $sourceUrl=$sourceUrl!=='' ? agent_normalize_url($sourceUrl) : '';
    } catch (Throwable $e) { return false; }
    // Search API result URLs are evidence only. No domain visits or downloads.
    if ($sourceUrl!=='' && $sourceId!==null) {
        $query=$pdo->prepare('SELECT origin FROM agent_sources WHERE id=?');
        $query->execute([$sourceId]);
        $origin=$query->fetchColumn();
        if (!is_string($origin) || !agent_same_origin($sourceUrl,$origin)) return false;
    }
    $pending=(int)$pdo->query("SELECT COUNT(*) FROM agent_topic_ideas WHERE status='queued'")->fetchColumn();
    if ($pending>=200) return false;
    $hash=hash('sha256',mb_strtolower($title,'UTF-8'));
    $stmt=$pdo->prepare('INSERT OR IGNORE INTO agent_topic_ideas
       (topic_hash,raw_title,question,industry,language,source_url,source_method,source_id)
       VALUES (?,?,?,?,?,?,?,?)');
    $question=preg_match('/[?？]|^(?:如何|怎样|怎么|为什么|是否|能否|How |Why |What |Can |When |Where )/iu',$title)
        ? $title : '';
    $stmt->execute([
        $hash,$title,$question,mb_substr($industry,0,100),mb_substr($language,0,25),
        $sourceUrl,$method,$sourceId,
    ]);
    return $stmt->rowCount()===1;
}

function agent_topic_approve(PDO $pdo,int $id): int
{
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare("SELECT * FROM agent_topic_ideas WHERE id=? AND status='review'");
        $stmt->execute([$id]);
        $idea=$stmt->fetch();
        if (!$idea || $idea['guide_id']!==null) throw new RuntimeException('该原创选题尚未通过人工审核或已创建草稿');
        $quality=$pdo->prepare('SELECT status FROM agent_topic_quality WHERE idea_id=?');
        $quality->execute([$id]);
        $qualityStatus=$quality->fetchColumn();
        if ($qualityStatus!==false && !in_array($qualityStatus,['reviewed','waived'],true)) {
            throw new RuntimeException('原创教程的独立质量审查尚未完成；请先完成检查或填写人工复核理由');
        }
        $media=$pdo->prepare('SELECT audio_status,video_status FROM agent_topic_av WHERE idea_id=?');
        $media->execute([$id]);
        $mediaState=$media->fetch();
        if ($mediaState && ($mediaState['audio_status']==='pending' || $mediaState['video_status']==='pending')) {
            throw new RuntimeException('原创配音或视频还在生成队列；请完成或手动跳过后再审核');
        }
        if ($idea['asset_status']==='pending') throw new RuntimeException('原创插画任务未完成，请完成后再确认草稿');
        if (mb_strlen(trim(strip_tags($idea['html'])))<90) {
            throw new RuntimeException('文章正文内容不足，无法建立草稿');
        }
        $title=mb_substr((string)$idea['title'],0,180);
        $summary=mb_substr((string)$idea['summary'],0,440);
        $slug=make_slug($title).'-'.bin2hex(random_bytes(4));
        $insert=$pdo->prepare("INSERT INTO guides(title,slug,summary,body_html,status,tags) VALUES (?,?,?,?,'draft',?)");
        $insert->execute([$title,$slug,$summary,sanitize_content($idea['html']),mb_substr($idea['industry'],0,80)]);
        $guideId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE agent_topic_ideas SET status='draft',guide_id=? WHERE id=?")->execute([$guideId,$id]);
        $pdo->commit();
        agent_event($pdo,'topic_draft','success','人工审核通过 AI 原创选题，已创建未发布草稿');
        return $guideId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
