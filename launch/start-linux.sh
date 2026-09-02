#!/usr/bin/env bash
# KAMRYNNE QUE — start the app and open a browser. No internet needed.
cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is not installed. On Debian/Ubuntu:  sudo apt install php-cli php-sqlite3" >&2
  exit 1
fi

( for _ in $(seq 1 40); do
    if curl -s -o /dev/null http://127.0.0.1:8080/ 2>/dev/null; then
      (xdg-open http://localhost:8080 >/dev/null 2>&1 || true); break
    fi
    sleep 0.3
  done ) &

exec bash ./serve.sh
