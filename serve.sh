#!/usr/bin/env bash
#
# Run KAMRYNNE QUE on this machine, with no internet connection required.
#
# This is the intended way to run it at a venue: the app, the database and the
# spectator board all live on this device. Phones on the same Wi-Fi — including
# a phone hotspot with no internet behind it — reach it over the LAN.
#
#   ./serve.sh            # port 8080
#   ./serve.sh 9000       # a different port
#
set -euo pipefail

PORT="${1:-8080}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is not installed. Install PHP 8.1 or newer, then run this again." >&2
  exit 1
fi

PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')
if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]; }; then
  echo "PHP ${PHP_MAJOR}.${PHP_MINOR} found; 8.1 or newer is required." >&2
  exit 1
fi

if ! php -m | grep -qi '^pdo_sqlite$'; then
  echo "The pdo_sqlite extension is missing. Enable it, then run this again." >&2
  exit 1
fi

mkdir -p "$ROOT/data"

# Best-effort LAN address so you can read it out to people at the venue.
LAN_IP=""
if command -v ipconfig >/dev/null 2>&1; then
  for i in en0 en1 en2; do
    LAN_IP=$(ipconfig getifaddr "$i" 2>/dev/null || true)
    [ -n "$LAN_IP" ] && break
  done
fi
if [ -z "$LAN_IP" ] && command -v hostname >/dev/null 2>&1; then
  LAN_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || true)
fi

echo
echo "  KAMRYNNE QUE — running locally, no internet needed"
echo "  ─────────────────────────────────────────────────"
echo "  Organizer   http://localhost:${PORT}"
[ -n "$LAN_IP" ] && echo "  On the LAN  http://${LAN_IP}:${PORT}"
echo "  Database    ${ROOT}/data/que.sqlite"
echo
echo "  Spectators open the board link on the same Wi-Fi."
echo "  Stop with Ctrl-C."
echo

exec php -S "0.0.0.0:${PORT}" -t "$ROOT"
