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
curl -fsS http://127.0.0.1:8099/ | grep -q 'TCL雷鸟电视查看和绑定酷狗账号'
curl -fsS http://127.0.0.1:8099/guide/tcl-leiniao-kugou-account | grep -q '不要点微信图标绑定'
curl -fsS 'http://127.0.0.1:8099/?q=%E9%85%B7%E7%8B%97' | grep -q 'TCL雷鸟电视查看和绑定酷狗账号'
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
php -S 127.0.0.1:8100 tests/mock_ai.php >/tmp/howhub-mock-ai.log 2>&1 &
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
response=$(curl -fsS -b "$jar" --data-urlencode "csrf_token=$token" \
  --data-urlencode 'html=<h2>操作</h2><p>点击按钮<img src="https://example.org/a.png"></p><p>扫码<img src="https://example.org/b.png"></p>' \
  http://127.0.0.1:8099/admin/import/generate)
php -r '$j=json_decode($argv[1],true); if (!$j["ok"] || $j["image_count"]!==2 || !preg_match("~/admin/guides/edit\\?id=\\d+~",$j["edit_url"])) exit(1);' "$response"
php -r 'require "app/bootstrap.php"; $g=database()->query("SELECT * FROM guides WHERE title = '\''模拟生成的教程'\'' ORDER BY id DESC LIMIT 1")->fetch(); if(!$g || $g["status"]!=="draft" || substr_count($g["body_html"],"<img ")!==2 || !str_contains($g["body_html"],"example.org/a.png")) exit(1);'
echo 'HowHub mock chat2api e2e passed: encrypted config / model discovery / AI draft / image placeholders'

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
  --data-urlencode 'html=<h2>扫码教程</h2><p>第一步<img src="https://example.org/a.png"></p><p>第二步<img src="https://example.org/b.png"></p>' \
  http://127.0.0.1:8099/admin/import/generate/stream)
STREAM_DATA="$stream" php -r '
$s=getenv("STREAM_DATA");
if (substr_count($s,"event: delta") < 2 || substr_count($s,"event: complete") !== 1) exit(1);
preg_match("/event: complete\\s+data: ([^\\r\\n]+)/",$s,$m);
$d=json_decode($m[1] ?? "",true);
if (!$d || !$d["ok"] || $d["image_count"] !== 2) exit(2);
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
echo 'HowHub streaming AI e2e passed: chunked SSE import + optimize / final validation / auth / CSRF'

