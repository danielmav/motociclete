#!/usr/bin/env bash
# Aduce DB-ul local de dev la zi cu productia (sursa de adevar).
# Read-only pe server (doar mysqldump prin database/prod_dump.php); scrie DOAR
# in DB-ul local de dev (DB_LOCAL_* din .env local, implicit `motociclete`).
#
# Necesita: alias SSH `dualmotors` configurat in ~/.ssh/config (vezi CLAUDE.md).
# Ruleaza din radacina proiectului:
#   bash database/sync_from_prod.sh
set -euo pipefail
cd "$(dirname "$0")/.."

SSH_HOST="dualmotors"
REMOTE_APP_DIR="/home/dualmotors/public_html/motociclete.com.ro"
REMOTE_PHP="/usr/local/bin/ea-php81"
REMOTE_DUMP="dm_sync_$$.sql"

LOCAL_DB_HOST=$(grep -m1 '^DB_LOCAL_HOST=' .env | cut -d= -f2-)
LOCAL_DB_USER=$(grep -m1 '^DB_LOCAL_USER=' .env | cut -d= -f2-)
LOCAL_DB_PASS=$(grep -m1 '^DB_LOCAL_PASS=' .env | cut -d= -f2- | tr -d "'\"")
LOCAL_DB_NAME=$(grep -m1 '^DB_LOCAL_NAME=' .env | cut -d= -f2-)
LOCAL_DUMP="$(pwd)/database/.sync_tmp.sql"

echo "==> Dump read-only pe server ($REMOTE_APP_DIR)..."
ssh "$SSH_HOST" "cd '$REMOTE_APP_DIR' && $REMOTE_PHP database/prod_dump.php ~/$REMOTE_DUMP"

echo "==> Descarc dump-ul..."
scp "$SSH_HOST:~/$REMOTE_DUMP" "$LOCAL_DUMP"

echo "==> Sterg dump-ul de pe server..."
ssh "$SSH_HOST" "rm -f ~/$REMOTE_DUMP"

MYSQL_BIN=$(ls /c/laragon/bin/mysql/*/bin/mysql.exe 2>/dev/null | head -1)
if [ -z "$MYSQL_BIN" ]; then
  echo "Nu gasesc mysql.exe sub C:/laragon/bin/mysql/*/bin/" >&2
  exit 1
fi

echo "==> Import in DB local '$LOCAL_DB_NAME'..."
if [ -n "$LOCAL_DB_PASS" ]; then
  MYSQL_PWD="$LOCAL_DB_PASS" "$MYSQL_BIN" -h "$LOCAL_DB_HOST" -u "$LOCAL_DB_USER" --default-character-set=utf8mb4 "$LOCAL_DB_NAME" < "$LOCAL_DUMP"
else
  "$MYSQL_BIN" -h "$LOCAL_DB_HOST" -u "$LOCAL_DB_USER" --default-character-set=utf8mb4 "$LOCAL_DB_NAME" < "$LOCAL_DUMP"
fi

rm -f "$LOCAL_DUMP"
echo "==> Gata. DB local '$LOCAL_DB_NAME' e la zi cu productia."
