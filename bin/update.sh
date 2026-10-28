#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
ROOT="$(cd -- "${HOWHUB_ROOT:-$(dirname -- "${BASH_SOURCE[0]}")/..}" && pwd -P)"
cd "$ROOT"
BASE="${HOWHUB_BACKUP_ROOT:-/root/howhub-backups}"
PHP="${HOWHUB_PHP:-}"
WEB="${HOWHUB_WEB_USER:-www}"
KEEP="${HOWHUB_BACKUP_KEEP:-7}"
MARKER="$ROOT/storage/.maintenance"
BACKUP=""
BEFORE=""
MAINT=0
CHANGED=0
log() { printf '[HowHub update] %s\n' "$*"; }
die() { log "错误：$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die '请通过 sudo bash bin/update.sh 执行'
[[ $BASE == /* && $BASE != "$ROOT" && $BASE != "$ROOT/"* ]] || die '备份目录必须是项目之外的绝对路径'
[[ $KEEP =~ ^[1-9][0-9]?$ ]] || die '备份保留数量必须为 1–99'
[[ -d .git && -f public/index.php && -d storage && -d public/uploads ]] || die '项目目录不完整'
[[ -f storage/howhub.sqlite ]] || die '缺少已初始化的 SQLite 数据库'
[[ ! -e "$MARKER" && ! -L storage && ! -L public/uploads ]] || die '检测到遗留维护标记或符号链接'
[[ -z "$(find storage public/uploads -type l -print -quit)" ]] || die '数据目录包含符号链接，拒绝遗漏备份'
for tool in git flock tar sha256sum du df; do command -v "$tool" >/dev/null || die "缺少命令 $tool"; done
id "$WEB" >/dev/null 2>&1 || die "PHP 用户 $WEB 不存在"
if [[ -z $PHP ]]; then
  for candidate in /www/server/php/{83,84,82}/bin/php /usr/bin/php8.3 /usr/bin/php; do
    if [[ -x $candidate ]] && "$candidate" -r 'exit(extension_loaded("pdo_sqlite")?0:1);' >/dev/null 2>&1; then
      PHP="$candidate"
      break
    fi
  done
fi
[[ -n $PHP && -x $PHP ]] || die '找不到支持 pdo_sqlite 的 PHP CLI，请设置 HOWHUB_PHP'
"$PHP" -r 'exit(extension_loaded("pdo_sqlite")?0:1);' || die 'PHP 缺少 pdo_sqlite'
mkdir -p -m 700 "$BASE"
chmod 700 "$BASE"
exec 9>"$BASE/.update.lock"
flock -n 9 || die '另一个更新器正在运行'
dbcheck() {
  "$PHP" -r '
    $db = new PDO("sqlite:".$argv[1], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($db->query("PRAGMA quick_check")->fetchColumn() !== "ok") exit(2);
  ' "$1"
}
snapshot() {
  local dest="$1"
  mkdir -m 700 "$dest"
  "$PHP" -r '
    $db=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("PRAGMA busy_timeout=10000");
    $db->exec("VACUUM INTO ".$db->quote($argv[2]));
  ' "$ROOT/storage/howhub.sqlite" "$dest/howhub.sqlite"
  dbcheck "$dest/howhub.sqlite"
  tar -C "$ROOT" -czf "$dest/storage.tar.gz" \
    --exclude='storage/howhub.sqlite' \
    --exclude='storage/howhub.sqlite-wal' \
    --exclude='storage/howhub.sqlite-shm' \
    --exclude='storage/.maintenance' storage
  tar -C "$ROOT" -czf "$dest/uploads.tar.gz" public/uploads
  ( cd "$dest"; sha256sum howhub.sqlite storage.tar.gz uploads.tar.gz > SHA256SUMS )
  printf '%s\n' "$BEFORE" > "$dest/commit.sha"
  touch "$dest/COMPLETE"
}
verify() {
  local dest="$1"
  [[ -f $dest/COMPLETE && -f $dest/SHA256SUMS && -f $dest/commit.sha ]] || return 1
  ( cd "$dest"; sha256sum -c SHA256SUMS >/dev/null ) || return 1
  tar -tzf "$dest/storage.tar.gz" >/dev/null || return 1
  tar -tzf "$dest/uploads.tar.gz" >/dev/null || return 1
  dbcheck "$dest/howhub.sqlite"
}
restore() {
  local dest="$1" stage
  verify "$dest" || { log '备份校验失败，未覆盖当前数据' >&2; return 1; }
  stage="$(mktemp -d "$BASE/.restore-XXXXXXXX")"
  tar -xzf "$dest/storage.tar.gz" -C "$stage" || return 1
  tar -xzf "$dest/uploads.tar.gz" -C "$stage" || return 1
  [[ -d $stage/storage && -d $stage/public/uploads ]] || return 1
  cp "$dest/howhub.sqlite" "$stage/storage/howhub.sqlite"
  dbcheck "$stage/storage/howhub.sqlite"
  mv "$ROOT/storage" "$dest/failed-live-storage" || return 1
  mv "$ROOT/public/uploads" "$dest/failed-live-uploads" || return 1
  mv "$stage/storage" "$ROOT/storage" || return 1
  mv "$stage/public/uploads" "$ROOT/public/uploads" || return 1
  chown -R "$WEB:$WEB" "$ROOT/storage" "$ROOT/public/uploads" || return 1
  chmod 770 "$ROOT/storage"
  chmod 775 "$ROOT/public/uploads"
  rmdir "$stage/public" "$stage" 2>/dev/null || true
}
finish() {
  local code=$? okay=1
  trap - EXIT INT TERM
  if (( code != 0 && CHANGED == 1 )); then
    log '更新验证失败，自动回滚 Git 版本和全部数据…'
    git reset --hard "$BEFORE" >/dev/null || okay=0
    restore "$BACKUP" || okay=0
    if (( okay == 1 )); then
      printf 'rolled-back\n' > "$BACKUP/RESULT"
      rm -f "$MARKER"
      log "恢复完成；失败现场保存在 $BACKUP/failed-live-*"
    else
      mkdir -p "$ROOT/storage"
      : > "$MARKER"
      log "回滚未能完成，保留维护状态，请人工检查备份 $BACKUP" >&2
      exit 1
    fi
  else
    (( MAINT == 0 )) || rm -f "$MARKER"
    if (( code != 0 )); then log '更新未应用，原有数据保持不变。' >&2; fi
  fi
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
[[ "$(git branch --show-current)" == main ]] || die '仅允许 main 分支'
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || die '存在本地代码修改，拒绝自动覆盖'
BEFORE="$(git rev-parse HEAD)"
log "当前版本 $(git rev-parse --short=12 HEAD)；获取远程版本…"
GIT_TERMINAL_PROMPT=0 git fetch --no-tags origin refs/heads/main:refs/remotes/origin/main
TARGET="$(git rev-parse refs/remotes/origin/main)"
if [[ "$TARGET" == "$BEFORE" ]]; then
  log '已是最新，无需重复更新或备份'
  exit 0
fi
git merge-base --is-ancestor "$BEFORE" "$TARGET" || die '远程不是快进更新，拒绝覆盖'
SIZE="$(du -sb storage public/uploads | awk '{x+=$1}END{print x+0}')"
FREE="$(df -B1 --output=avail "$BASE" | tail -1 | tr -d ' ')"
[[ $FREE =~ ^[0-9]+$ ]] || die '无法取得磁盘剩余空间'
(( FREE > SIZE * 2 + 104857600 )) || die '空间不足：要求数据量 2 倍加 100MB 可用空间'
BACKUP="$BASE/$(date -u '+%Y%m%dT%H%M%SZ')-$(git rev-parse --short=12 HEAD)-$$"
: > "$MARKER"
chmod 644 "$MARKER"
MAINT=1
log '临时进入维护状态，创建一致性备份…'
snapshot "$BACKUP"
verify "$BACKUP" || die '备份校验未通过，拒绝更新'
log "备份成功：$BACKUP"
CHANGED=1
git merge --ff-only "$TARGET"
log '验证 PHP 语法及数据库启动…'
while IFS= read -r path; do
  [[ -f $path ]] && "$PHP" -l "$path" >/dev/null
done < <(git ls-files '*.php')
"$PHP" -r '
  require $argv[1]."/app/bootstrap.php";
  $db=database();
  if ($db->query("PRAGMA quick_check")->fetchColumn() !== "ok") exit(2);
' "$ROOT"
chown -R "$WEB:$WEB" "$ROOT/storage" "$ROOT/public/uploads"
chmod 770 "$ROOT/storage"
chmod 775 "$ROOT/public/uploads"
[[ -f VERSION && -f public/index.php ]] || die '缺少网站入口文件'
printf 'success\n' > "$BACKUP/RESULT"
log "更新成功：$(cat VERSION) ($(git rev-parse --short=12 HEAD))"
log "备份目录：$BACKUP"
mapfile -t good < <(find "$BASE" -mindepth 1 -maxdepth 1 -type d -name '20??????T??????Z-*' -print | sort -r | while IFS= read -r dir; do
  [[ $(cat "$dir/RESULT" 2>/dev/null || true) == success ]] && printf '%s\n' "$dir"
done)
if (( ${#good[@]} > KEEP )); then
  for (( i=KEEP; i<${#good[@]}; i++ )); do
    rm -rf -- "${good[$i]}" || log '过期备份清理失败，保留原文件'
  done
fi
