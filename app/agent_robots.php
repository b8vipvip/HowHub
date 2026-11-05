<?php
declare(strict_types=1);

const AGENT_ID = 'HowHubDiscoveryBot';
const AGENT_UA = 'HowHubDiscoveryBot/1.0 (+https://howhub.k2n.cn/agent)';

/** RFC 9309-style group selection: best user-agent, most specific matching rule. */
function agent_robots_decision(string $robots, string $path): array
{
    $groups = [];
    $current = ['agents'=>[], 'rules'=>[], 'delay'=>0];
    $flush = static function () use (&$groups, &$current): void {
        if ($current['agents']) $groups[] = $current;
        $current = ['agents'=>[], 'rules'=>[], 'delay'=>0];
    };
    foreach (preg_split('/\r\n|\r|\n/', substr($robots,0,524288)) ?: [] as $line) {
        $line = trim(explode('#',$line,2)[0]);
        if ($line === '' || !str_contains($line, ':')) continue;
        [$key,$value] = array_map('trim',explode(':',$line,2));
        $key = strtolower($key);
        if ($key === 'user-agent') {
            if ($current['rules'] || $current['delay']) $flush();
            $current['agents'][] = strtolower($value);
        } elseif ($current['agents']) {
            if (in_array($key,['allow','disallow'],true)) $current['rules'][] = [$key,$value];
            if ($key === 'crawl-delay' && is_numeric($value)) $current['delay'] = min(86400,max(0,(int)ceil((float)$value)));
        }
    }
    $flush();
    $bestAgent = -1;
    $selected = [];
    foreach ($groups as $group) {
        foreach ($group['agents'] as $agent) {
            if ($agent !== '*' && !str_contains(strtolower(AGENT_ID), $agent)) continue;
            $specificity = $agent === '*' ? 0 : strlen($agent);
            if ($specificity > $bestAgent) { $selected = []; $bestAgent = $specificity; }
            if ($specificity === $bestAgent) $selected[] = $group;
        }
    }
    $best = -1;
    $allow = true;
    $delay = 0;
    foreach ($selected as $group) {
        $delay = max($delay,$group['delay']);
        foreach ($group['rules'] as [$kind,$rule]) {
            if ($rule === '') continue;
            $terminal = str_ends_with($rule,'$');
            if ($terminal) $rule = substr($rule,0,-1);
            $pattern = '~^' . str_replace('\*','.*',preg_quote($rule,'~')) . ($terminal ? '$' : '') . '~u';
            if (!preg_match($pattern,$path)) continue;
            $length = strlen(str_replace('*','',$rule));
            if ($length > $best || ($length === $best && $kind === 'allow')) {
                $best = $length;
                $allow = $kind === 'allow';
            }
        }
    }
    return ['allowed'=>$allow,'delay'=>$delay];
}

/**
 * Cache robots for 24h. A fresh read consumes the site's rate slot;
 * the requested document is not fetched in the same tick.
 * 404/410 = absent robots; 401/403/429/5xx or network error => fail closed.
 */
function agent_robots_gate(PDO $pdo, array $source, string $url, ?callable $fixture = null, ?int $now = null): array
{
    $now ??= time();
    if ($source['robots_checked'] < $now - 86400 || $source['robots_state'] === 'unknown') {
        $robotsUrl = $source['origin'] . '/robots.txt';
        $budget = agent_rate_take($pdo,$source,$robotsUrl,'robots',0,$now);
        if (!$budget['ok']) return ['ready'=>false,'allowed'=>false,'message'=>$budget['reason']];
        try {
            $response = url_import_fetch($robotsUrl, 524288, $fixture,
                [401,403,404,410,429,500,502,503,504], AGENT_UA, true);
            $status = (int)$response['status'];
            $state = ($status === 200 || $status === 404 || $status === 410) ? 'available' : 'blocked';
            $txt = $status === 200 ? (string)$response['body'] : '';
            $message = $state === 'available' ? 'robots.txt 已检查' : 'robots.txt 拒绝或不可用：HTTP '.$status;
        } catch (Throwable $e) {
            $state = 'blocked'; $txt = '';
            $message = 'robots.txt 无法确认，已暂停采集';
        }
        $pdo->prepare('UPDATE agent_sources SET robots_checked=?,robots_text=?,robots_state=?,last_message=?,
            blocked_until=CASE WHEN ? = ? THEN ? ELSE blocked_until END WHERE id=?')
            ->execute([$now,$txt,$state,$message,$state,'blocked',$now+86400,$source['id']]);
        agent_event($pdo,'robots',$state === 'available' ? 'success' : 'blocked',$message,(int)$source['id']);
        return ['ready'=>false,'allowed'=>false,'message'=>$message];
    }
    if ($source['robots_state'] !== 'available') return ['ready'=>true,'allowed'=>false,'message'=>'robots.txt 不允许或状态不可确认'];
    $path = (string)(parse_url($url,PHP_URL_PATH) ?: '/');
    $query = parse_url($url,PHP_URL_QUERY);
    if (is_string($query) && $query !== '') $path .= '?' . $query;
    $decision = agent_robots_decision((string)$source['robots_text'],$path);
    return ['ready'=>true,'allowed'=>$decision['allowed'],'delay'=>$decision['delay'],
        'message'=>$decision['allowed'] ? 'robots.txt 允许' : 'robots.txt 禁止访问该页面'];
}

/** Atomic reservation with global/day/domain/page caps and enforced cooldown. */
function agent_rate_take(PDO $pdo, array $source, string $url, string $purpose, int $robotsDelay = 0, ?int $now = null): array
{
    $now ??= time();
    $begun=false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $begun=true;
        $stmt = $pdo->prepare('SELECT * FROM agent_sources WHERE id=?');
        $stmt->execute([$source['id']]);
        $fresh = $stmt->fetch();
        if (!$fresh || !$fresh['enabled']) throw new RuntimeException('该来源当前未启用');
        if ((int)$fresh['blocked_until'] > $now) throw new RuntimeException('该来源仍在暂停或退避期');
        if ((int)$fresh['next_fetch'] > $now) throw new RuntimeException('该来源访问冷却期尚未结束');
        $since = $now - 86400;
        $count = $pdo->prepare('SELECT COUNT(*) FROM agent_requests WHERE source_id=? AND requested_at>?');
        $count->execute([$fresh['id'],$since]);
        if ((int)$count->fetchColumn() >= min(20,max(1,(int)$fresh['daily_limit']))) {
            throw new RuntimeException('该网站 24 小时请求次数达到上限');
        }
        $global = $pdo->prepare('SELECT COUNT(*) FROM agent_requests WHERE requested_at>?');
        $global->execute([$since]);
        if ((int)$global->fetchColumn() >= 40) throw new RuntimeException('全局每日请求预算已达到上限');
        $hash = hash('sha256',agent_normalize_url($url));
        $recent = $pdo->prepare('SELECT MAX(requested_at) FROM agent_requests WHERE url_hash=?');
        $recent->execute([$hash]);
        $last = (int)$recent->fetchColumn();
        $pageCooldown = $purpose === 'robots' ? 86400 : ($purpose === 'feed' ? 21600 : 172800);
        if ($last && $last > $now - $pageCooldown) throw new RuntimeException('同一页面还在访问冷却期');
        $gap = max(300,min(86400,(int)$fresh['min_interval']),$robotsDelay);
        $pdo->prepare('UPDATE agent_sources SET next_fetch=? WHERE id=?')->execute([$now+$gap,$fresh['id']]);
        $pdo->prepare('INSERT INTO agent_requests(source_id,url_hash,purpose,requested_at) VALUES (?,?,?,?)')
            ->execute([$fresh['id'],$hash,$purpose,$now]);
        $pdo->exec('COMMIT');
        $begun=false;
        return ['ok'=>true,'next'=>$now+$gap];
    } catch (Throwable $e) {
        if ($begun) $pdo->exec('ROLLBACK');
        return ['ok'=>false,'reason'=>$e->getMessage()];
    }
}
