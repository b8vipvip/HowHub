#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php -S 127.0.0.1:8099 -t public public/router.php >/tmp/howhub-e2e.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT
for attempt in $(seq 1 30); do
  if curl -fsS http://127.0.0.1:8099/healthz >/dev/null 2>&1; then break; fi
  sleep .25
done
curl -fsS http://127.0.0.1:8099/healthz | grep -q '"ok":true'
curl -fsS http://127.0.0.1:8099/agent | grep -q 'HowHubDiscoveryBot'
# A site under maintenance must not accept new PHP requests.
touch storage/.maintenance
maintenance_status=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/healthz)
rm -f storage/.maintenance
test "$maintenance_status" = 503

curl -fsS http://127.0.0.1:8099/ | grep -q 'TCL雷鸟电视查看和绑定酷狗账号'
curl -fsS http://127.0.0.1:8099/guide/tcl-leiniao-kugou-account | grep -q '不要点微信图标绑定'

# Article detail is a focused reading view: no topbar, category name or TOC sidebar.
article_html=$(curl -fsS http://127.0.0.1:8099/guide/tcl-leiniao-kugou-account)
ARTICLE_HTML="$article_html" php -r '
$html = getenv("ARTICLE_HTML");
foreach (["<main class=\"reader-shell reader-clean", "TCL雷鸟电视查看和绑定酷狗账号", "不要点微信图标绑定", "返回全部文章"] as $required) {
    if (!str_contains($html, $required)) exit(1);
}
foreach (["<header class=\"topbar\"", "<nav class=\"nav-links\"", "本页目录", "reader-sidebar", "guide-category-pill", "class=\"breadcrumb\"", "设备与电视"] as $forbidden) {
    if (str_contains($html, $forbidden)) exit(2);
}
if (substr_count($html, "<img ") !== 5) exit(3);
if (!preg_match("~<div class=\"article-heading\"><h1>[^<]+</h1></div>~", $html)) exit(4);
if (str_contains($html, "class=\"article-meta\"")) exit(5);
if (str_contains($html, "<div class=\"article-heading\"><h1>TCL雷鸟电视查看和绑定酷狗账号</h1><p>")) exit(6);
';
curl -fsS http://127.0.0.1:8099/ | grep -q '<header class="topbar">'
echo 'Article reading mode e2e passed: no navbar/category/TOC, content and images preserved'

curl -fsS 'http://127.0.0.1:8099/?q=%E9%85%B7%E7%8B%97' | grep -q 'TCL雷鸟电视查看和绑定酷狗账号'

# Homepage shows every published article without cover thumbnails or pagination.
php <<'PHP'
<?php
require 'app/bootstrap.php';
$pdo = database();
$insert = $pdo->prepare("INSERT OR IGNORE INTO guides (title,slug,summary,status,published_at) VALUES (?,?,?,'published',CURRENT_TIMESTAMP)");
for ($i = 1; $i <= 14; $i++) {
    $insert->execute(["首页列表样例 $i", "ci-home-guide-$i", "第 $i 篇文章"]);
}
$pdo->prepare("INSERT OR IGNORE INTO guides (title,slug,summary,status) VALUES (?,?,?,'draft')")
    ->execute(['首页未发布草稿', 'ci-home-draft', '不应出现在首页']);
PHP
home=$(curl -fsS http://127.0.0.1:8099/)
HOME_HTML="$home" php -r '
$html = getenv("HOME_HTML");
if (substr_count($html, "class=\"home-article-item\"") < 15) exit(1);
foreach (["home-article-list", "首页列表样例 14", "TCL雷鸟电视查看和绑定酷狗账号"] as $value) {
    if (!str_contains($html, $value)) exit(2);
}
foreach (["首页未发布草稿", "class=\"guide-grid\"", "class=\"hero-art\"", "class=\"pagination\"", "class=\"guide-cover\""] as $value) {
    if (str_contains($html, $value)) exit(3);
}
';
curl -fsS 'http://127.0.0.1:8099/?q=%E9%A6%96%E9%A1%B5%E5%88%97%E8%A1%A8%E6%A0%B7%E4%BE%8B' | grep -q '首页列表样例 14'
if curl -fsS 'http://127.0.0.1:8099/?q=%E9%A6%96%E9%A1%B5%E5%88%97%E8%A1%A8%E6%A0%B7%E4%BE%8B' | grep -q 'TCL雷鸟电视查看和绑定酷狗账号'; then
    echo "Homepage search filter failed" >&2; exit 1
fi
echo 'Homepage list e2e passed: all published / no covers / search / drafts hidden'
php -r 'require "app/bootstrap.php"; database()->prepare("INSERT OR REPLACE INTO users (username,password_hash) VALUES (?,?)")->execute(["testadmin",password_hash("test-password-12345", PASSWORD_DEFAULT)]);'
login=$(mktemp)
jar=$(mktemp)
trap 'kill "$server_pid" 2>/dev/null || true; rm -f "$login" "$jar"' EXIT
curl -fsS -c "$jar" http://127.0.0.1:8099/admin/login > "$login"
token=$(grep -o 'name="csrf_token" value="[^"]*"' "$login" | head -1 | cut -d '"' -f 4)
test -n "$token"
status=$(curl -sS -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST \
  --data-urlencode "csrf_token=$token" --data-urlencode 'username=testadmin' \
  --data-urlencode 'password=test-password-12345' http://127.0.0.1:8099/admin/login)
test "$status" = "303"
curl -fsS -b "$jar" http://127.0.0.1:8099/admin | grep -q '教程总数'
# A held telemetry lock must not prevent a NEW admin session from logging in.
held_login_jar=$(mktemp)
held_login_page=$(mktemp)
held_marker=$(mktemp)
rm -f "$held_marker"
flock -x storage/diagnostics/.lock bash -c 'touch "$1"; sleep 3' _ "$held_marker" &
held_pid=$!
for attempt in $(seq 1 60); do
  [[ -f "$held_marker" ]] && break
  sleep .02
done
test -f "$held_marker"
curl -fsS --max-time 2 -c "$held_login_jar" http://127.0.0.1:8099/admin/login > "$held_login_page"
held_csrf=$(grep -o 'name="csrf_token" value="[^"]*"' "$held_login_page" | head -1 | cut -d '"' -f 4)
test -n "$held_csrf"
held_status=$(curl -sS --max-time 2 -b "$held_login_jar" -c "$held_login_jar" -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=$held_csrf" --data-urlencode 'username=testadmin' \
  --data-urlencode 'password=test-password-12345' http://127.0.0.1:8099/admin/login)
test "$held_status" = "303"
curl -fsS --max-time 2 -b "$held_login_jar" http://127.0.0.1:8099/admin | grep -q '教程总数'
wait "$held_pid"
rm -f "$held_login_jar" "$held_login_page" "$held_marker"
echo 'Login lock contention e2e passed: authenticates with held diagnostics lock'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/guides/new | grep -q 'rich-editor'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/guides/new | grep -q 'media-dialog'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/media | grep -q '素材管理'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/media/list | grep -q '"ok":true'
blocked=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' -X POST \
  -d 'title=should-not-save' http://127.0.0.1:8099/admin/guides/save)
test "$blocked" = "419"
echo 'HowHub e2e passed: homepage / guide / search / admin / editor / media / CSRF'
# AI settings and cross-site paste are admin-only and CSRF-protected.
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/ai-settings | grep -q 'AI 供应商'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/guides/new | grep -q 'ai-source-html'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/guides/new | grep -q 'url-import-generate'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/agent | grep -q '内容发现 Agent'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/agent | grep -q 'AI 自动寻找国内外知识来源网站'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/agent | grep -q '原创选题 Agent'
agent_auth=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/admin/agent)
test "$agent_auth" = '303'
agent_csrf=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' -X POST -d 'action=global&enabled=1' http://127.0.0.1:8099/admin/agent/action)
test "$agent_csrf" = '419'
original_csrf=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' -X POST -d 'action=topic_add&title=unauthorized' http://127.0.0.1:8099/admin/agent/action)
test "$original_csrf" = '419'
topic_created=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=$token" \
  --data-urlencode 'action=topic_add' \
  --data-urlencode 'title=CI原创新增问题如何检查网络连接？' \
  --data-urlencode 'industry=信息技术' \
  http://127.0.0.1:8099/admin/agent/action)
test "$topic_created" = '303'
php -r 'require "app/bootstrap.php"; $pdo=database(); $q=$pdo->query("SELECT id,source_url,status FROM agent_topic_ideas WHERE raw_title=\x27CI原创新增问题如何检查网络连接？\x27 LIMIT 1"); $row=$q->fetch(); if(!$row || $row["source_url"]!=="" || $row["status"]!=="queued") exit(1); $pdo->prepare("DELETE FROM agent_topic_ideas WHERE id=?")->execute([$row["id"]]);'
echo 'Original Agent admin e2e passed: CSRF / admin-only manual topic / private queued creation'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/guides/new | grep -q 'editor-toggle-source'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/guides/new | grep -q 'editor-preview-dialog'
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/guides/new | grep -q 'editor-ai-optimize'
html=$(curl -fsS -b "$jar" --data-urlencode "csrf_token=$token" \
  --data-urlencode 'html=<h2 onclick="bad()">标题</h2><script>bad()</script><img src="https://example.com/image.png">' \
  http://127.0.0.1:8099/admin/import/clean)
php -r '$r=json_decode($argv[1],true); if(!$r["ok"] || strpos($r["html"],"<h2>")===false || strpos($r["html"],"<script")!==false || strpos($r["html"],"<img")===false) exit(1);' "$html"
blocked=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' -X POST \
  -d 'html=<p>some source</p>' http://127.0.0.1:8099/admin/import/generate)
test "$blocked" = "419"
unconfigured=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=$token" --data-urlencode 'html=<p>some source</p>' \
  http://127.0.0.1:8099/admin/import/generate)
test "$unconfigured" = "400"
echo 'HowHub AI import e2e passed: settings / editor / HTML paste / CSRF / missing config'
# Fake chat2api upstream: verify encrypted provider config, model discovery, AI-created draft.
slow_signal=$(mktemp)
rm -f "$slow_signal"
HOWHUB_CI_SLOW_SIGNAL="$slow_signal" php -S 127.0.0.1:8100 tests/mock_ai.php >/tmp/howhub-mock-ai.log 2>&1 &
mock_pid=$!
trap 'kill "$server_pid" "$mock_pid" 2>/dev/null || true; rm -f "$login" "$jar"' EXIT
for attempt in $(seq 1 30); do
  if curl -s http://127.0.0.1:8100/v1/models >/dev/null 2>&1; then break; fi
  sleep .25
done
status=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=$token" \
  --data-urlencode 'base_url=http://127.0.0.1:8100/v1' \
  --data-urlencode 'api_key=sk-ci-local-token' \
  --data-urlencode 'model=ci-demo' http://127.0.0.1:8099/admin/ai-settings)
test "$status" = "303"
curl -fsS -b "$jar" --data-urlencode "csrf_token=$token" http://127.0.0.1:8099/admin/ai/models >/dev/null
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/ai-settings | grep -q 'ci-demo'
before_import=$(php -r 'require "app/bootstrap.php"; echo database()->query("SELECT COUNT(*) FROM guides")->fetchColumn();')
response=$(curl -fsS -b "$jar" --data-urlencode "csrf_token=$token" \
  --data-urlencode 'html=<article><h2>操作</h2><p>点击按钮<img src="/uploads/ci-fixtures/a.png"></p><p>扫码<img data-src="/uploads/ci-fixtures/b.png"></p></article>' \
  http://127.0.0.1:8099/admin/import/generate)
php -r '$j=json_decode($argv[1],true); if (!$j["ok"] || $j["image_count"]!==2 || !str_contains($j["html"], "<h2") || substr_count($j["html"], "<img ") !== 2 || str_contains($j["html"], "<script") || str_contains($j["html"], "javascript:")) exit(1);' "$response"
after_import=$(php -r 'require "app/bootstrap.php"; echo database()->query("SELECT COUNT(*) FROM guides")->fetchColumn();')
test "$before_import" = "$after_import"
echo 'HowHub mock chat2api e2e passed: encrypted config / model discovery / raw HTML to editor / no database writes'

# A live admin request must reject private-network URL fetching before calling AI.
private_link=$(curl -fsSN --max-time 10 -b "$jar" --data-urlencode "csrf_token=$token" \
  --data-urlencode 'url=http://127.0.0.1:8100/secret' \
  http://127.0.0.1:8099/admin/import/url/stream)
echo "$private_link" | grep -q 'event: error'
if echo "$private_link" | grep -q 'event: complete'; then
  echo 'Unsafe URL was accepted by the import endpoint' >&2; exit 1
fi
# The same AI provider must classify the fetched article before rendering HTML.
php <<'PHP'
<?php
require 'app/bootstrap.php';
$article = '<article><h2>电视绑定操作教程</h2>'
    . '<p>第一步打开设置并选择网络连接选项。</p>'
    . '<p>第二步根据提示登录账号并点击确认绑定。</p>'
    . '<p>第三步检查屏幕状态，验证绑定是否已经完成。</p></article>';
$fixture = static fn(string $url): array => [
    'status'=>200,'body'=>$article,'headers'=>['content-type'=>'text/html'],
];
$source = url_import_prepare(database(), 'https://docs.example.com/guide', null, $fixture);
$result = url_import_generate($source);
if (!str_contains($result['html'], 'https://docs.example.com/guide')
    || !str_contains($result['html'], '<h2')
    || $result['source_kind'] !== 'webpage'
    || $result['suggested_title'] === '') throw new RuntimeException('URL AI 导入未完成');
$notGuide = '<article><h2>CI_NOT_GUIDE 大促广告</h2>'
    . '<p>今日商品促销活动，不包括任何实际操作步骤或教学内容。</p>'
    . '<p>仅展示报价、优惠、型号和宣传信息，没有任何使用方法。</p></article>';
$fixtureBad = static fn(string $url): array => [
    'status'=>200,'body'=>$notGuide,'headers'=>['content-type'=>'text/html'],
];
$badSource = url_import_prepare(database(), 'https://docs.example.com/ads', null, $fixtureBad);
try {
    url_import_generate($badSource);
    throw new LogicException('非教程广告被允许导入');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), '不属于')) throw $e;
}
PHP
echo 'HowHub URL AI e2e passed: admin CSRF / SSRF / model classification / non-guide refusal / HTML result'

# The autonomous agent is separate from manual URL imports: score and summary
# are generated, but no publicly visible guide is created.
php <<'PHP'
<?php
require 'app/bootstrap.php';
$before=(int)database()->query('SELECT COUNT(*) FROM guides')->fetchColumn();
$review=agent_ai_evaluate(str_repeat("设备连接的方法包括开机后进入设置，按步骤绑定账号并检查连接状态。\n",15),
    'https://docs.example.com/guide','信息技术');
if (!$review['approved'] || $review['score']<70) throw new RuntimeException('agent classification rejected useful content');
$html=agent_summary_html($review,'https://docs.example.com/guide');
if (!str_contains($html,'https://docs.example.com/guide') || str_contains($html,'<script')) {
    throw new RuntimeException('agent original summary/provenance missing');
}
$bad=agent_ai_evaluate(str_repeat('CI_AGENT_AD_ONLY 推销和广告。\n',15),
    'https://docs.example.com/sales','广告');
if ($bad['approved']) throw new RuntimeException('agent mistakenly approved advertising content');
$after=(int)database()->query('SELECT COUNT(*) FROM guides')->fetchColumn();
if ($before!==$after) throw new RuntimeException('agent unexpectedly published or created a guide');
PHP
echo 'HowHub agent AI e2e passed: relevance score / original summary / provenance / reject promotion / no publish'


# Optional source page URL resolves relative text links without fetching the page.
linked=$(curl -fsS -b "$jar" --data-urlencode "csrf_token=$token" \
  --data-urlencode 'source_url=https://docs.example.com/guide/start.html' \
  --data-urlencode 'html=<article><h2>连接操作</h2><p>阅读<a href="../docs">官方文档</a>后再进行设置</p></article>' \
  http://127.0.0.1:8099/admin/import/generate)
php -r '$j=json_decode($argv[1],true); if (!$j["ok"] || $j["link_count"] !== 1 || !str_contains($j["html"], "https://docs.example.com/docs") || !str_contains($j["html"], "官方文档") || str_contains($j["html"], "javascript:")) exit(1);' "$linked"
echo 'HowHub universal HTML URL/link HTTP e2e passed'


# The optimization request is an editor-only preview, not a database write.
blocked=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' -X POST \
  --data-urlencode 'html=<p>do not save</p>' http://127.0.0.1:8099/admin/guides/optimize)
test "$blocked" = "419"
optimized=$(curl -fsS -b "$jar" --data-urlencode "csrf_token=$token" \
  --data-urlencode 'title=电视教程' \
  --data-urlencode 'html=<h2>第一步</h2><p>操作不要发送验证码</p><figure><img src="/uploads/one.png"></figure>' \
  http://127.0.0.1:8099/admin/guides/optimize)
php -r '$j=json_decode($argv[1],true); if (!$j["ok"] || $j["image_count"] !== 1 || !str_contains($j["html"], "guide-img-medium") || !str_contains($j["html"], "/uploads/one.png") || str_contains($j["html"], "<script")) exit(1);' "$optimized"
echo 'HowHub AI optimize e2e passed: mock provider / image retention / HTML whitelist / CSRF'

# Diagnostic dashboard must be admin-only and errors must be exportable without secrets.
unauth=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/admin/diagnostics)
test "$unauth" = "303"
curl -fsS -b "$jar" http://127.0.0.1:8099/admin/diagnostics > "$login"
grep -q '诊断日志' "$login"
grep -q '运行版本' "$login"
csrf_blocked=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' -X POST -d 'action=upload_ui' http://127.0.0.1:8099/admin/diagnostics/client)
test "$csrf_blocked" = "419"
client=$(curl -fsS -b "$jar" --data-urlencode "csrf_token=$token" \
  --data-urlencode 'feature=editor' --data-urlencode 'action=preview_ui' \
  --data-urlencode 'message=sk-super-secret' http://127.0.0.1:8099/admin/diagnostics/client)
echo "$client" | grep -q '"ok":true'
curl -fsS -b "$jar" 'http://127.0.0.1:8099/admin/diagnostics/export?feature=editor' > "$login"
grep -q 'preview_ui' "$login"
if grep -q 'sk-super-secret' "$login"; then
  echo 'Diagnostics exported sensitive client payload' >&2; exit 1
fi
# System storage and diagnostics are not served from the public webroot.
private=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/storage/diagnostics/events.jsonl)
test "$private" = "404"
echo 'HowHub diagnostics e2e passed: admin / filter / export / CSRF / no client secrets / private storage'
# Streaming endpoints must emit multiple incremental delta frames and exactly one complete event.
stream=$(curl -fsSN --max-time 30 -b "$jar" --data-urlencode "csrf_token=$token" \
  --data-urlencode 'html=<h2>扫码教程</h2><p>第一步<img src="/uploads/ci-fixtures/a.png"></p><p>第二步<img src="/uploads/ci-fixtures/b.png"></p>' \
  http://127.0.0.1:8099/admin/import/generate/stream)
STREAM_DATA="$stream" php -r '
$s=getenv("STREAM_DATA");
if (substr_count($s,"event: delta") < 2 || substr_count($s,"event: complete") !== 1) exit(1);
preg_match("/event: complete\\s+data: ([^\\r\\n]+)/",$s,$m);
$d=json_decode($m[1] ?? "",true);
if (!$d || !$d["ok"] || $d["image_count"] !== 2 || !str_contains($d["html"], "<img ") || str_contains($d["html"], "<script")) exit(2);
'
optimized_stream=$(curl -fsSN --max-time 30 -b "$jar" --data-urlencode "csrf_token=$token" \
  --data-urlencode 'title=电视教程' \
  --data-urlencode 'html=<h2>第一步</h2><p>操作不要发送验证码</p><figure><img src="/uploads/one.png"></figure>' \
  http://127.0.0.1:8099/admin/guides/optimize/stream)
STREAM_DATA="$optimized_stream" php -r '
$s=getenv("STREAM_DATA");
if (substr_count($s,"event: delta") < 2 || substr_count($s,"event: complete") !== 1) exit(1);
preg_match("/event: complete\\s+data: ([^\\r\\n]+)/",$s,$m);
$d=json_decode($m[1] ?? "",true);
if (!$d || !$d["ok"] || !str_contains($d["html"],"/uploads/one.png")) exit(2);
'
blocked=$(curl -sS -o /dev/null -w '%{http_code}' -X POST \
  -d 'html=<p>unauthorized</p>' http://127.0.0.1:8099/admin/import/generate/stream)
test "$blocked" = '303'
blocked=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' -X POST \
  -d 'html=<p>missing csrf</p>' http://127.0.0.1:8099/admin/guides/optimize/stream)
test "$blocked" = '419'
echo 'HowHub streaming AI e2e passed: raw HTML stream + optimize / final validation / auth / CSRF'


# Exercise the real application with three concurrent PHP workers and the
# SAME administrator cookie while a non-stream AI request takes 3.5 seconds.
workers_log=$(mktemp)
slow_result=$(mktemp)
PHP_CLI_SERVER_WORKERS=3 php -S 127.0.0.1:8101 -t public public/router.php >"$workers_log" 2>&1 &
workers_pid=$!
trap 'kill "$server_pid" "$mock_pid" "$workers_pid" 2>/dev/null || true; rm -f "$login" "$jar" "$slow_signal" "$workers_log" "$slow_result"' EXIT
for attempt in $(seq 1 40); do
  curl -fsS --max-time 1 http://127.0.0.1:8101/healthz >/dev/null 2>&1 && break
  sleep .1
done
curl -fsS --max-time 2 http://127.0.0.1:8101/healthz >/dev/null
curl -fsS --max-time 15 -b "$jar" \
  --data-urlencode "csrf_token=$token" \
  --data-urlencode 'html=<p>CI_SLOW_IMPORT_2026 操作步骤</p>' \
  http://127.0.0.1:8101/admin/import/generate > "$slow_result" &
slow_pid=$!
for attempt in $(seq 1 60); do
  [[ -f "$slow_signal" ]] && break
  sleep .1
done
if [[ ! -f "$slow_signal" ]]; then
  echo 'Slow AI fixture did not start' >&2
  wait "$slow_pid" || true
  exit 1
fi
# Both GET routes must finish before the deliberately delayed AI response.
test "$(curl -sS --max-time 2 -b "$jar" -o /dev/null -w '%{http_code}' http://127.0.0.1:8101/admin)" = 200
test "$(curl -sS --max-time 2 -b "$jar" -o /dev/null -w '%{http_code}' http://127.0.0.1:8101/)" = 200
wait "$slow_pid"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if (!$j || empty($j["ok"])) exit(1);' "$slow_result"
echo 'Session concurrency passed: slow non-stream AI does not block same-session GET'
