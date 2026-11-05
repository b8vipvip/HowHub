<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$pdo=database();
$rules="User-agent: *\nDisallow: /private/\nAllow: /private/help/\nCrawl-delay: 8\n\nUser-agent: HowHubDiscoveryBot\nDisallow: /members/\nAllow: /members/public/\nCrawl-delay: 12";
foreach ([
    '/members/secret'=>false,
    '/members/public/setup'=>true,
    '/private/secret'=>true,
    '/news'=>true,
] as $path=>$expected) {
    $decision=agent_robots_decision($rules,$path);
    if ($decision['allowed']!==$expected || $decision['delay']!==12) {
        throw new RuntimeException('robots group parsing or longest-path Allow precedence failed: '.$path);
    }
}
$wide="User-agent: *\nDisallow: /secret\nAllow: /secret/open\nDisallow: /*?token=\n";
if (agent_robots_decision($wide,'/secret/private')['allowed'] ||
    !agent_robots_decision($wide,'/secret/open/a')['allowed'] ||
    agent_robots_decision($wide,'/some?token=x')['allowed']) {
    throw new RuntimeException('robots wildcard/precedence failed');
}
try {
    agent_add_source($pdo,'http://127.0.0.1/','','','','summary');
    throw new LogicException('allowed private-network source');
} catch (InvalidArgumentException $e) { /* expected */ }
try {
    agent_add_source($pdo,'https://docs.example.org','https://evil.example.com/feed.xml','','','summary');
    throw new LogicException('accepted offsite discovery feed');
} catch (InvalidArgumentException $e) { /* expected */ }
foreach ([
    'https://docs.example.org/open/../admin',
    'https://docs.example.org/public/%2e%2e/admin',
    'https://docs.example.org/public/%2fadmin',
] as $ambiguous) {
    try {
        agent_normalize_url($ambiguous);
        throw new LogicException('Ambiguous robots path was accepted');
    } catch (InvalidArgumentException $e) { /* expected */ }
}
try {
    url_import_fetch('https://docs.example.org/guide', 10000,
        static fn(string $url):array=>['status'=>302,'body'=>'','headers'=>['location'=>'/members/private']],
        [],AGENT_UA,true);
    throw new LogicException('Autonomous redirect bypassed target robots check');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(),'robots')) throw $e;
}
$domain='https://docs-agent-test.example.org';
$id=agent_add_source($pdo,$domain,$domain.'/feed.xml','信息技术','English','summary');
try {
    $stmt=$pdo->prepare('SELECT * FROM agent_sources WHERE id=?');
    $stmt->execute([$id]);
    $source=$stmt->fetch();
    if ($source['enabled'] || $source['rights_mode']!=='summary') throw new RuntimeException('new sources must default disabled and summary-only');
    try { agent_set_rights($pdo,$id,'licensed','');throw new LogicException('licensed mode without permission'); }
    catch (InvalidArgumentException $e) {}
    $pdo->prepare('UPDATE agent_sources SET enabled=1,min_interval=300,daily_limit=2 WHERE id=?')->execute([$id]);
    $stmt->execute([$id]);
    $source=$stmt->fetch();
    $now=time();
    $fixture=static function (string $url) use ($domain): array {
        if ($url===$domain.'/robots.txt') return ['status'=>200,'body'=>"User-agent: HowHubDiscoveryBot\nDisallow: /no-crawl\nAllow: /\nCrawl-delay: 10\n",'headers'=>['Content-Type'=>'text/plain']];
        if ($url===$domain.'/feed.xml') return ['status'=>200,'body'=>'<rss><channel><item><title>Guide A</title><link>'.$domain.'/how-to-setup</link></item><item><title>Guide B</title><link>'.$domain.'/faq</link></item><item><title>External</title><link>https://other.example.com/a</link></item></channel></rss>','headers'=>['Content-Type'=>'application/rss+xml']];
        throw new RuntimeException('Unexpected fixture request '.$url);
    };
    $first=agent_robots_gate($pdo,$source,$domain.'/feed.xml',$fixture,$now);
    if ($first['ready'] || $first['allowed']) throw new RuntimeException('robots fetch must be a separate scheduled operation');
    $stmt->execute([$id]);$source=$stmt->fetch();
    $allowed=agent_robots_gate($pdo,$source,$domain.'/how-to-setup',$fixture,$now+1);
    $disallowed=agent_robots_gate($pdo,$source,$domain.'/no-crawl/private',$fixture,$now+1);
    if (!$allowed['allowed'] || $disallowed['allowed']) throw new RuntimeException('robots access policy not respected');
    $before=agent_rate_take($pdo,$source,$domain.'/feed.xml','feed',0,$now+1);
    if ($before['ok']) throw new RuntimeException('site cooldown ignored');
    $after=agent_rate_take($pdo,$source,$domain.'/feed.xml','feed',0,$now+310);
    if (!$after['ok']) throw new RuntimeException('allowed request incorrectly blocked: '.($after['reason']??''));
    $over=agent_rate_take($pdo,$source,$domain.'/how-to-setup','page',0,$now+620);
    if ($over['ok']) throw new RuntimeException('daily site budget ignored');
    $items=agent_feed_items('<rss><channel><item><link>'.$domain.'/a</link><title>A</title></item><item><link>https://elsewhere.example/b</link><title>B</title></item></channel></rss>',
        $domain.'/feed.xml',$domain);
    if (count($items)!==1 || !agent_enqueue($pdo,$source,$items[0]['url'],$items[0]['title']) ||
        agent_enqueue($pdo,$source,$items[0]['url'],$items[0]['title'])) {
        throw new RuntimeException('feed discovery cross-domain restriction or dedupe broken');
    }
    $index=agent_feed_items('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>'.$domain.'/sitemap-posts.xml</loc></sitemap><sitemap><loc>https://outside.example.com/other.xml</loc></sitemap></sitemapindex>',
        $domain.'/sitemap.xml',$domain);
    if (count($index)!==1 || $index[0]['kind']!=='feed' ||
        $index[0]['url']!==$domain.'/sitemap-posts.xml') {
        throw new RuntimeException('Nested sitemap index parsing not restricted to same-domain');
    }
    $rich='<article><h2>网络设置方法</h2><p>按照提示进入路由器控制面板完成设置。</p>'.
        '<table><tr><th colspan="2">配置对照</th></tr><tr><td>端口</td><td>8080</td></tr></table>'.
        '<math><semantics><mi>x</mi><annotation encoding="application/x-tex">x^2 + y^2</annotation></semantics></math>'.
        '<pre class="mermaid">graph TD; A-->B</pre><svg><circle cx="3" cy="3"/></svg></article>';
    $features=agent_source_features($rich);
    $formatted=agent_structured_html($features);
    if ($features['counts']['tables']!==1 || $features['counts']['formulas']<1 ||
        $features['counts']['diagrams']!==1 || !$features['warnings'] ||
        !str_contains($formatted,'<table>') || !str_contains($formatted,'colspan="2"') || !str_contains($formatted,'x^2 + y^2') ||
        !str_contains($formatted,'graph TD')) {
        throw new RuntimeException('table/formula/diagram safe structure preservation failed');
    }
} finally {
    $pdo->prepare('DELETE FROM agent_sources WHERE id=?')->execute([$id]);
}
echo "HowHub discovery agent passed: robots/permission/host-budget/global-cap/feed-dedupe/structured-formats\n";
