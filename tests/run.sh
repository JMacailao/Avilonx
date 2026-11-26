#!/usr/bin/env bash
# AvilonX PHP tests: a fresh test database, the PHP built-in server, the API
# checks (tests/api.cjs) and every page in a browser (tests/smoke.cjs).
# Needs PHP 8.1+ with pdo_mysql, a MySQL/MariaDB server, Node 18+ and Playwright.
#   DB_USER=root DB_PASS= bash tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/.."
DB_NAME=${DB_NAME:-avilonx_test}; DB_USER=${DB_USER:-root}; DB_PASS=${DB_PASS:-}; DB_HOST=${DB_HOST:-127.0.0.1}; PORT=${PORT:-8090}
MYSQL=(mysql -h "$DB_HOST" -u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
"${MYSQL[@]}" "$DB_NAME" < database/avilonx.sql
TMP=$(mktemp -d)
cat > "$TMP/config.php" <<EOF
<?php return ['db_host' => '$DB_HOST', 'db_name' => '$DB_NAME', 'db_user' => '$DB_USER', 'db_pass' => '$DB_PASS',
  'app_secret' => 'test-secret-0123456789', 'storage_dir' => '$TMP/uploads', 'site_url' => 'http://127.0.0.1:$PORT'];
EOF
AVILONX_CONFIG="$TMP/config.php" php -S 127.0.0.1:$PORT -t . > "$TMP/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; rm -rf "$TMP"' EXIT
sleep 1
BASE=http://127.0.0.1:$PORT node tests/api.cjs
BASE=http://127.0.0.1:$PORT node tests/smoke.cjs
