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
