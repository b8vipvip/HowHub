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
blocked=$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' -X POST \
  -d 'title=should-not-save' http://127.0.0.1:8099/admin/guides/save)
test "$blocked" = "419"
echo 'HowHub e2e passed: homepage / guide / search / admin / editor / CSRF'
