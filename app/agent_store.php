<?php
declare(strict_types=1);

/** Opt-in per-domain agent state and audit records. Never modify published guides. */
function agent_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agent_sources (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 origin TEXT NOT NULL UNIQUE,
 feed_url TEXT NOT NULL DEFAULT '',
 industry TEXT NOT NULL DEFAULT '',
 region TEXT NOT NULL DEFAULT '',
 rights_mode TEXT NOT NULL DEFAULT 'summary' CHECK(rights_mode IN ('summary','licensed')),
 enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)),
 min_interval INTEGER NOT NULL DEFAULT 1800,
 daily_limit INTEGER NOT NULL DEFAULT 8,
 next_fetch INTEGER NOT NULL DEFAULT 0,
 robots_checked INTEGER NOT NULL DEFAULT 0,
 robots_text TEXT NOT NULL DEFAULT '',
 robots_state TEXT NOT NULL DEFAULT 'unknown',
 robots_delay INTEGER NOT NULL DEFAULT 0,
 blocked_until INTEGER NOT NULL DEFAULT 0,
 last_message TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS agent_candidates (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 source_id INTEGER NOT NULL REFERENCES agent_sources(id) ON DELETE CASCADE,
 url TEXT NOT NULL,
 url_hash TEXT NOT NULL UNIQUE,
 title TEXT NOT NULL DEFAULT '',
 status TEXT NOT NULL DEFAULT 'queued'
   CHECK(status IN ('queued','review','rejected','error','draft')),
 score INTEGER NOT NULL DEFAULT 0,
 evidence TEXT NOT NULL DEFAULT '',
 body_html TEXT NOT NULL DEFAULT '',
 format_notes TEXT NOT NULL DEFAULT '',
 content_hash TEXT NOT NULL DEFAULT '',
 guide_id INTEGER REFERENCES guides(id) ON DELETE SET NULL,
 checked_at INTEGER NOT NULL DEFAULT 0,
 next_check INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS agent_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 source_id INTEGER NOT NULL REFERENCES agent_sources(id) ON DELETE CASCADE,
 url_hash TEXT NOT NULL,
 purpose TEXT NOT NULL,
 requested_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS agent_requests_day ON agent_requests(source_id,requested_at);
CREATE INDEX IF NOT EXISTS agent_candidates_work ON agent_candidates(status,next_check,id);
CREATE TABLE IF NOT EXISTS agent_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 source_id INTEGER,
 candidate_id INTEGER,
 action TEXT NOT NULL,
 outcome TEXT NOT NULL,
 detail TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
}

function agent_event(PDO $pdo, string $action, string $outcome, string $detail = '', int $source = 0, int $candidate = 0): void
{
    $pdo->prepare('INSERT INTO agent_events(source_id,candidate_id,action,outcome,detail) VALUES (?,?,?,?,?)')
        ->execute([$source ?: null,$candidate ?: null,$action,$outcome,mb_substr($detail,0,350)]);
    diag_event($outcome === 'error' || $outcome === 'blocked' ? 'warning' : 'info',
        'discovery_agent',$action,$outcome,['source_id'=>$source,'candidate_id'=>$candidate]);
}

function agent_origin(string $value): string
{
    $safe = import_page_url($value);
    $p = parse_url($safe);
    if (!is_array($p) || strtolower($p['scheme']) !== 'https') {
        throw new InvalidArgumentException('自动内容发现仅支持 HTTPS 来源站点');
    }
    if (isset($p['query']) || isset($p['fragment']) || isset($p['port'])) {
        throw new InvalidArgumentException('来源站点应填写 HTTPS 域名，不含查询参数或端口');
    }
    return 'https://' . strtolower($p['host']);
}

/** Must use configured, exact source hostname; never traverse external links. */
function agent_same_origin(string $url, string $origin): bool
{
    try {
        $p = parse_url(import_page_url($url));
        $o = parse_url($origin);
        return is_array($p) && is_array($o) && $p['scheme'] === 'https' &&
            strtolower($p['host']) === strtolower($o['host']) &&
            !isset($p['port']) && !isset($p['user']);
    } catch (Throwable $e) { return false; }
}

function agent_normalize_url(string $url): string
{
    $p = parse_url(import_page_url($url));
    if (!is_array($p) || strtolower($p['scheme']) !== 'https' || isset($p['port'])) {
        throw new InvalidArgumentException('候选页面仅支持 HTTPS');
    }
    $path = $p['path'] ?? '/';
    $params = [];
    if (isset($p['query'])) {
        parse_str($p['query'], $params);
        foreach (array_keys($params) as $name) {
            if (preg_match('/^(utm_|fbclid$|gclid$|ref$|source$|spm$)/i', $name)) unset($params[$name]);
        }
        ksort($params);
    }
    $query = $params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
    return 'https://' . strtolower($p['host']) . ($path === '' ? '/' : $path) . $query;
}

function agent_add_source(PDO $pdo, string $site, string $feed, string $industry, string $region, string $rights): int
{
    $origin = agent_origin($site);
    if (agent_same_origin($origin, agent_origin('https://howhub.k2n.cn'))) {
        throw new InvalidArgumentException('不能把本站加入自动发现来源');
    }
    $feed = trim($feed);
    if ($feed !== '') {
        $feed = agent_normalize_url($feed);
        if (!agent_same_origin($feed, $origin)) throw new InvalidArgumentException('RSS 或 Sitemap 地址必须属于同一来源站点');
    }
    if (!in_array($rights, ['summary','licensed'], true)) throw new InvalidArgumentException('内容许可模式无效');
    if ($rights === 'licensed') throw new InvalidArgumentException('完整媒体转载仅对已核实的许可来源开放；请先以摘要模式添加并核实权利后启用');
    $stmt = $pdo->prepare('INSERT INTO agent_sources(origin,feed_url,industry,region,rights_mode) VALUES (?,?,?,?,?)');
    $stmt->execute([$origin,$feed,mb_substr($industry,0,100),mb_substr($region,0,80),'summary']);
    return (int)$pdo->lastInsertId();
}

function agent_enqueue(PDO $pdo, array $source, string $url, string $title = ''): bool
{
    $url = agent_normalize_url($url);
    if (!agent_same_origin($url, $source['origin'])) return false;
    $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    if (preg_match('~/(?:login|logout|signup|admin|cart|checkout|search|account)(?:/|$)~', $path)) return false;
    $hash = hash('sha256', $url);
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO agent_candidates(source_id,url,url_hash,title) VALUES (?,?,?,?)');
    $stmt->execute([(int)$source['id'],$url,$hash,mb_substr($title,0,180)]);
    return $stmt->rowCount() === 1;
}
