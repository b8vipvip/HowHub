<?php
declare(strict_types=1);

/** Parse bounded RSS, Atom or sitemap XML without loading external entities. */
function agent_feed_items(string $xml, string $feedUrl, string $origin): array
{
    if (strlen($xml) > 524288 || trim($xml) === '' || !str_starts_with(ltrim($xml), '<')) {
        throw new RuntimeException('RSS/Sitemap 数据无效或超过 512KB');
    }
    $doc = new DOMDocument('1.0','UTF-8');
    $old = libxml_use_internal_errors(true);
    try {
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($old);
    }
    if (!$ok) throw new RuntimeException('来源 RSS/Sitemap XML 不是有效格式');
    $root = strtolower((string)($doc->documentElement?->localName ?? ''));
    if (!in_array($root,['rss','feed','rdf','urlset','sitemapindex'],true)) {
        throw new RuntimeException('发现入口只允许 RSS、Atom 或 Sitemap URL 集合');
    }
    $xpath = new DOMXPath($doc);
    $items = [];
    if ($root === 'urlset' || $root === 'sitemapindex') {
        $nodes = $xpath->query($root === 'urlset' ? '//*[local-name()="url"]' : '//*[local-name()="sitemap"]');
        foreach ($nodes ?: [] as $item) {
            $loc = $xpath->query('./*[local-name()="loc"]',$item)?->item(0)?->textContent ?? '';
            $items[] = ['url'=>trim($loc),'title'=>'','kind'=>$root === 'sitemapindex' ? 'feed' : 'page'];
            if (count($items)>=40) break;
        }
    } elseif ($root === 'feed') {
        $nodes = $xpath->query('//*[local-name()="entry"]');
        foreach ($nodes ?: [] as $item) {
            $link = '';
            foreach ($xpath->query('./*[local-name()="link"]',$item) ?: [] as $anchor) {
                if ($anchor instanceof DOMElement && in_array($anchor->getAttribute('rel'),['','alternate'],true)) {
                    $link = $anchor->getAttribute('href'); break;
                }
            }
            $title = $xpath->query('./*[local-name()="title"]',$item)?->item(0)?->textContent ?? '';
            $items[] = ['url'=>$link,'title'=>$title];
            if (count($items)>=40) break;
        }
    } else {
        $nodes = $xpath->query('//*[local-name()="item"]');
        foreach ($nodes ?: [] as $item) {
            $link = $xpath->query('./*[local-name()="link"]',$item)?->item(0)?->textContent ?? '';
            $title = $xpath->query('./*[local-name()="title"]',$item)?->item(0)?->textContent ?? '';
            $items[] = ['url'=>$link,'title'=>$title];
            if (count($items)>=40) break;
        }
    }
    $clean = [];
    foreach ($items as $item) {
        $link = import_resolve_url($item['url'],$feedUrl);
        if ($link === '' || !agent_same_origin($link,$origin)) continue;
        $clean[] = ['url'=>$link,'title'=>mb_substr(trim($item['title']),0,180),
            'kind'=>(string)($item['kind'] ?? 'page')];
    }
    return $clean;
}

function agent_discover(PDO $pdo, array $source, ?callable $fixture = null, string $nestedFeed = ''): array
{
    $url = $nestedFeed ?: ($source['feed_url'] ?: $source['origin'].'/sitemap.xml');
    if (!agent_same_origin($url,$source['origin'])) throw new RuntimeException('来源发现入口跨域');
    $gate = agent_robots_gate($pdo,$source,$url,$fixture);
    if (!$gate['ready']) return ['status'=>'robots','message'=>$gate['message']];
    if (!$gate['allowed']) return ['status'=>'blocked','message'=>$gate['message']];
    $budget = agent_rate_take($pdo,$source,$url,'feed',(int)($gate['delay'] ?? 0));
    if (!$budget['ok']) return ['status'=>'cooldown','message'=>$budget['reason']];
    try {
        $response = url_import_fetch($url,524288,$fixture,[],AGENT_UA,true);
        if (!agent_same_origin($response['url'],$source['origin'])) throw new RuntimeException('发现入口被重定向到其他网站');
        $items = agent_feed_items($response['body'],$response['url'],$source['origin']);
        $added = 0;
        $nested = 0;
        foreach ($items as $item) {
            if ($item['kind'] === 'feed') {
                $filePath = strtolower((string)parse_url($item['url'],PHP_URL_PATH));
                if (!str_ends_with($filePath,'.xml') && !str_ends_with($filePath,'.xml.gz')) continue;
                // Compressed XML is unsupported: never try unlimited decompression.
                if (str_ends_with($filePath,'.gz')) continue;
                $ins=$pdo->prepare('INSERT OR IGNORE INTO agent_feeds(source_id,url,url_hash) VALUES (?,?,?)');
                $ins->execute([$source['id'],$item['url'],hash('sha256',$item['url'])]);
                $nested += $ins->rowCount();
            } elseif (agent_enqueue($pdo,$source,$item['url'],$item['title'])) $added++;
            if ($added + $nested >= 20) break;
        }
        agent_event($pdo,'feed','success','新增网页候选 '.$added.'；子站点地图 '.$nested,(int)$source['id']);
        return ['status'=>'discovered','added'=>$added,'nested'=>$nested,'total'=>count($items)];
    } catch (Throwable $e) {
        agent_backoff($pdo,$source,$e->getMessage());
        return ['status'=>'error','message'=>$e->getMessage()];
    }
}

function agent_backoff(PDO $pdo, array $source, string $message): void
{
    // Conservative source-wide cooldown on 403/429/503/timeouts: never retry
    // immediately, rotate proxy identities or bypass challenges.
    $seconds = preg_match('/HTTP (?:403|429|503)|超时|timeout|访问限制/u',$message) ? 86400 : 21600;
    $pdo->prepare('UPDATE agent_sources SET blocked_until=?,last_message=? WHERE id=?')
        ->execute([time()+$seconds,mb_substr($message,0,250),(int)$source['id']]);
    agent_event($pdo,'fetch','blocked',$message,(int)$source['id']);
}
