#!/usr/bin/env bash
# CI-only isolated test: creates temporary Git repositories, never touches real site.
set -Eeuo pipefail
umask 077
[[ $EUID -eq 0 ]] || { echo 'Use sudo to run updater integration tests' >&2; exit 1; }
ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
PHP="${HOWHUB_PHP:-$(command -v php)}"
WEB="${HOWHUB_WEB_USER:-www-data}"
id "$WEB" >/dev/null
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/seed/bin" "$TMP/seed/app" "$TMP/seed/public/uploads" "$TMP/seed/storage"
cp "$ROOT/bin/update.sh" "$TMP/seed/bin/update.sh"
printf '0.8.0\n' > "$TMP/seed/VERSION"
printf '<?php echo "alive";\n' > "$TMP/seed/public/index.php"
cat > "$TMP/seed/app/bootstrap.php" <<'PHP'
<?php
function database(): PDO {
    return new PDO('sqlite:' . dirname(__DIR__) . '/storage/howhub.sqlite');
}
PHP
printf '/storage/*.sqlite*\n/storage/*.key\n/public/uploads/*\n' > "$TMP/seed/.gitignore"
touch "$TMP/seed/storage/.gitkeep" "$TMP/seed/public/uploads/.gitkeep"
(
  cd "$TMP/seed"
  git init -q -b main
  git config user.email updater-ci@example.invalid
  git config user.name 'HowHub CI'
  git add . && git commit -qm seed
  git init -q --bare "$TMP/remote.git"
  git remote add origin "$TMP/remote.git"
  git push -q origin main
)
git clone -q -b main "$TMP/remote.git" "$TMP/site"
mkdir -p "$TMP/site/storage/media_files" "$TMP/site/public/uploads"
"$PHP" -r '
  $db=new PDO("sqlite:".$argv[1]);
  $db->exec("CREATE TABLE content (value TEXT)");
  $db->exec("INSERT INTO content VALUES (".$db->quote("original").")");
' "$TMP/site/storage/howhub.sqlite"
printf 'private-ai-key' > "$TMP/site/storage/ai-secret.key"
printf 'original-image' > "$TMP/site/public/uploads/image.png"
printf 'original-document' > "$TMP/site/storage/media_files/guide.pdf"
export HOWHUB_BACKUP_ROOT="$TMP/backups"
export HOWHUB_WEB_USER="$WEB"
export HOWHUB_PHP="$PHP"
(
  cd "$TMP/seed"
  printf '0.8.1\n' > VERSION
  git add VERSION
  git commit -qm successful
  git push -q origin main
)
bash "$TMP/site/bin/update.sh"
[[ $(cat "$TMP/site/VERSION") == 0.8.1 ]]
[[ $(cat "$TMP/site/public/uploads/image.png") == original-image ]]
[[ $(cat "$TMP/site/storage/ai-secret.key") == private-ai-key ]]
[[ ! -e "$TMP/site/storage/.maintenance" ]]
first="$(find "$TMP/backups" -mindepth 1 -maxdepth 1 -type d -name '20*' | head -1)"
[[ -n $first && -f $first/COMPLETE && $(cat "$first/RESULT") == success ]]
[[ "$("$PHP" -r '$p=new PDO("sqlite:".$argv[1]);echo $p->query("SELECT value FROM content")->fetchColumn();' "$first/howhub.sqlite")" == original ]]
# Simulate an upgrade which mutates data then crashes at application startup.
(
  cd "$TMP/seed"
  cat > app/bootstrap.php <<'PHP'
<?php
function database(): PDO {
    $p = new PDO('sqlite:' . dirname(__DIR__) . '/storage/howhub.sqlite');
    $p->exec("UPDATE content SET value='mutated'");
    file_put_contents(dirname(__DIR__) . '/public/uploads/image.png', 'damaged-image');
    file_put_contents(dirname(__DIR__) . '/storage/media_files/guide.pdf', 'damaged-document');
    file_put_contents(dirname(__DIR__) . '/storage/ai-secret.key', 'damaged-key');
    throw new RuntimeException('simulated bootstrap crash');
}
PHP
  printf '0.8.2\n' > VERSION
  git add app/bootstrap.php VERSION
  git commit -qm broken
  git push -q origin main
)
if bash "$TMP/site/bin/update.sh"; then
  echo 'Broken release should have triggered rollback' >&2
  exit 1
fi
[[ $(cat "$TMP/site/VERSION") == 0.8.1 ]]
[[ $(cat "$TMP/site/public/uploads/image.png") == original-image ]]
[[ $(cat "$TMP/site/storage/media_files/guide.pdf") == original-document ]]
[[ $(cat "$TMP/site/storage/ai-secret.key") == private-ai-key ]]
[[ ! -e "$TMP/site/storage/.maintenance" ]]
[[ "$("$PHP" -r '$p=new PDO("sqlite:".$argv[1]);echo $p->query("SELECT value FROM content")->fetchColumn();' "$TMP/site/storage/howhub.sqlite")" == original ]]
rolled="$(find "$TMP/backups" -mindepth 1 -maxdepth 1 -type d -name '20*' | grep -v -F -x "$first" | head -1)"
[[ -n $rolled && $(cat "$rolled/RESULT") == rolled-back ]]
[[ -d "$rolled/failed-live-storage" && -d "$rolled/failed-live-uploads" ]]
# A subsequent successful release must prune only older successful backups
# while preserving the rollback forensic backup.
(
  cd "$TMP/seed"
  cat > app/bootstrap.php <<'PHP'
<?php
function database(): PDO {
    return new PDO('sqlite:' . dirname(__DIR__) . '/storage/howhub.sqlite');
}
PHP
  printf '0.8.3\n' > VERSION
  git add app/bootstrap.php VERSION
  git commit -qm recovered
  git push -q origin main
)
HOWHUB_BACKUP_KEEP=1 bash "$TMP/site/bin/update.sh"
[[ $(cat "$TMP/site/VERSION") == 0.8.3 ]]
success_count=0
rollback_count=0
for entry in "$TMP"/backups/20*; do
  [[ -d $entry ]] || continue
  state="$(cat "$entry/RESULT" 2>/dev/null || true)"
  [[ $state != success ]] || success_count=$((success_count + 1))
  [[ $state != rolled-back ]] || rollback_count=$((rollback_count + 1))
done
[[ $success_count -eq 1 && $rollback_count -eq 1 ]]
echo 'HowHub updater integration passed: consistent backup, rollback, preservation, retention'
