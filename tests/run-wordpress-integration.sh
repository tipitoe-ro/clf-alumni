#!/usr/bin/env bash
# Disposable real WordPress + MariaDB; never accepts an existing database.
set -euo pipefail
for tool in php curl tar mariadb-install-db mariadbd mariadb mariadb-admin; do
  command -v "$tool" >/dev/null || { echo "Required tool missing: $tool" >&2; exit 1; }
done
ROOT="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/clfa-wp-test.XXXXXXXX")"
DB_PID=""
cleanup() {
  if [[ -n "$DB_PID" ]]; then
    kill "$DB_PID" 2>/dev/null || true
    wait "$DB_PID" 2>/dev/null || true
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT
trap 'exit 130' INT TERM
export CLFA_TEST_ROOT="$WORK"
curl --fail --silent --show-error --location --retry 2 \
  https://wordpress.org/wordpress-6.8.3.tar.gz -o "$WORK/wordpress.tar.gz"
tar -xzf "$WORK/wordpress.tar.gz" -C "$WORK"
mariadb-install-db --no-defaults --datadir="$WORK/db" \
  --auth-root-authentication-method=normal --skip-test-db >"$WORK/install.log" 2>&1
mariadbd --no-defaults --datadir="$WORK/db" --socket="$WORK/mysql.sock" \
  --pid-file="$WORK/mysql.pid" --skip-networking --user="$(id -un)" \
  --log-error="$WORK/mysql.log" &
DB_PID=$!
for attempt in {1..100}; do
  if mariadb-admin --no-defaults --socket="$WORK/mysql.sock" -u root ping >/dev/null 2>&1; then break; fi
  if ! kill -0 "$DB_PID" 2>/dev/null; then cat "$WORK/mysql.log"; exit 1; fi
  sleep 0.1
done
mariadb --no-defaults --socket="$WORK/mysql.sock" -u root \
  -e 'CREATE DATABASE clfa_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
php "$ROOT/wordpress-integration/install.php"
php "$ROOT/wordpress-integration/admin-menu.php"
php "$ROOT/mailchimp-admin-integration-test.php"
php "$ROOT/mailchimp-admin-integration-test.php" --constant-key
php "$ROOT/wordpress-integration/profile-sync.php"
php "$ROOT/wordpress-integration/worker-retries.php"
php "$ROOT/mailchimp-webhook-integration-test.php"
