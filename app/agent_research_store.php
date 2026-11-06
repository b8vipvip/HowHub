<?php
declare(strict_types=1);

/**
 * Source research is an independent stage: results are proposals, NOT new
 * crawl permissions. Neither the AI nor a search result may enable a domain.
 */
function agent_research_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agent_research_topics (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 topic TEXT NOT NULL,
 language TEXT NOT NULL DEFAULT 'zh',
 region TEXT NOT NULL DEFAULT 'global',
 enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)),
 next_run INTEGER NOT NULL DEFAULT 0,
 last_run INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(topic, language, region)
);
CREATE TABLE IF NOT EXISTS agent_source_proposals (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 origin TEXT NOT NULL UNIQUE,
 example_url TEXT NOT NULL DEFAULT '',
 title TEXT NOT NULL DEFAULT '',
 snippet TEXT NOT NULL DEFAULT '',
 topic TEXT NOT NULL DEFAULT '',
 language TEXT NOT NULL DEFAULT '',
 method TEXT NOT NULL CHECK(method IN ('search_api','ai_suggestion')),
 state TEXT NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','accepted','rejected')),
 evidence TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at TEXT
);
CREATE INDEX IF NOT EXISTS agent_source_proposals_state ON agent_source_proposals(state,id DESC);
CREATE TABLE IF NOT EXISTS agent_research_runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 topic_id INTEGER NOT NULL REFERENCES agent_research_topics(id) ON DELETE CASCADE,
 method TEXT NOT NULL,
 query TEXT NOT NULL DEFAULT '',
 created_at INTEGER NOT NULL,
 results INTEGER NOT NULL DEFAULT 0,
 result TEXT NOT NULL DEFAULT 'started'
);
CREATE INDEX IF NOT EXISTS agent_research_runs_day ON agent_research_runs(created_at);
SQL);
    $topics=[
        ['家电设置与维修教程','zh','CN'],['软件操作与排障','zh','CN'],
        ['汽车维护与诊断','zh','CN'],['烹饪方法与烘焙','zh','CN'],
        ['DIY家居与工具','en','global'],['software development documentation','en','global'],
        ['open educational resources and study methods','en','global'],
        ['automotive repair and maintenance guides','en','global'],
        ['photography and video editing tutorials','en','global'],
        ['gardening and home growing guides','en','global'],
        ['manufacturing process and safety guides','en','global'],
        ['家居装修与家电安装','zh','CN'],
    ];
    $insert=$pdo->prepare('INSERT OR IGNORE INTO agent_research_topics(topic,language,region) VALUES (?,?,?)');
    foreach($topics as $entry) $insert->execute($entry);
}

function agent_research_propose(PDO $pdo, string $url, string $title, string $snippet,
    string $topic, string $language, string $method, string $evidence = ''): bool
{
    if (!in_array($method,['search_api','ai_suggestion'],true)) return false;
    try {
        $url=agent_normalize_url(trim($url));
        $host=(string)parse_url($url,PHP_URL_HOST);
        $origin=agent_origin('https://'.$host);
    } catch (Throwable $e) { return false; }
    $host=strtolower((string)parse_url($origin,PHP_URL_HOST));
    if (preg_match('/\.(?:local|localhost|internal|invalid|test|onion|lan)$/i',$host)) return false;
    if (str_contains($url,'@')) return false;
    $existing=$pdo->prepare('SELECT 1 FROM agent_sources WHERE origin=? LIMIT 1');
    $existing->execute([$origin]);
    if ($existing->fetchColumn()) return false;
    $stmt=$pdo->prepare('INSERT OR IGNORE INTO agent_source_proposals
      (origin,example_url,title,snippet,topic,language,method,evidence)
      VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $origin,$url,mb_substr($title,0,200),mb_substr($snippet,0,600),
        mb_substr($topic,0,120),mb_substr($language,0,20),$method,mb_substr($evidence,0,250),
    ]);
    return $stmt->rowCount()===1;
}

/** Human approval only, defaults disabled and summary-only after approval. */
function agent_research_accept(PDO $pdo, int $proposalId): int
{
    $stmt=$pdo->prepare("SELECT * FROM agent_source_proposals WHERE id=? AND state='pending'");
    $stmt->execute([$proposalId]);
    $p=$stmt->fetch();
    if (!$p) throw new RuntimeException('找不到待审核的来源网站候选');
    // Do not auto-follow unrelated paths or accept an AI claim as a licence.
    if (!agent_same_origin($p['example_url'],$p['origin'])) {
        throw new RuntimeException('来源候选地址已变更，无法验证主域名一致性');
    }
    $sourceId=agent_add_source($pdo,$p['origin'],'',
        $p['topic'],$p['language'],'summary');
    $pdo->prepare("UPDATE agent_source_proposals SET state='accepted',reviewed_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$proposalId]);
    agent_event($pdo,'source_research','success','人工批准候选网站；默认暂停、仅原创摘要',$sourceId);
    return $sourceId;
}

function agent_research_reject(PDO $pdo,int $proposalId): void
{
    $pdo->prepare("UPDATE agent_source_proposals SET state='rejected',reviewed_at=CURRENT_TIMESTAMP
      WHERE id=? AND state='pending'")->execute([$proposalId]);
}

function agent_research_add_topic(PDO $pdo,string $topic,string $language,string $region): int
{
    $topic=trim($topic);
    if (mb_strlen($topic)<3 || mb_strlen($topic)>120) throw new InvalidArgumentException('行业或知识主题长度需为 3 至 120 字符');
    if (!in_array($language,['zh','en','es','fr','de','ja','ko','pt','ar','hi','other'],true)) throw new InvalidArgumentException('主题语言不支持');
    $region=trim($region);
    if ($region==='' || mb_strlen($region)>50) throw new InvalidArgumentException('地区代码长度有误');
    $stmt=$pdo->prepare('INSERT OR IGNORE INTO agent_research_topics(topic,language,region) VALUES (?,?,?)');
    $stmt->execute([$topic,$language,$region]);
    return (int)($stmt->rowCount() ? $pdo->lastInsertId() : 0);
}
