#!/usr/bin/env bash
#
# Run KAMRYNNE QUE on this machine, with no internet connection required.
#
#   ./serve.sh                 HTTP on port 8080  — works on every device
#   ./serve.sh 9000            HTTP on another port
#   ./serve.sh --https         HTTPS on 8443 too — needed for the installable
#                              app and offline caching on phones and tablets
#
# Why --https exists: browsers only expose service workers, the Cache API and
# "install to home screen" on a secure context. https:// and localhost qualify;
# a LAN address like http://192.168.1.50:8080 does not. Without TLS the
# organizer's own machine gets the full offline app and everyone else does not.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORT=8080
TLS_PORT=8443
USE_TLS=0

for arg in "$@"; do
  case "$arg" in
    --https) USE_TLS=1 ;;
    --help|-h)
      sed -n '2,14p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    ''|*[!0-9]*) echo "Unknown option: $arg" >&2; exit 1 ;;
    *) PORT="$arg" ;;
  esac
done

# ------------------------------------------------------------- checks ------
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

# Checked with php -r rather than `php -m | grep -q`: grep -q closes the pipe on
# first match, PHP takes SIGPIPE, and `set -o pipefail` then reports the whole
# pipeline as failed even though the extension is present.
if ! php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'; then
  echo "The pdo_sqlite extension is missing. Enable it, then run this again." >&2
  exit 1
fi

mkdir -p "$ROOT/data"

# Best-effort LAN address, so you can read it out at the venue.
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
[ -z "$LAN_IP" ] && LAN_IP="127.0.0.1"

# --------------------------------------------------------- plain http ------
if [ "$USE_TLS" -eq 0 ]; then
  echo
  echo "  KAMRYNNE QUE — running locally, no internet needed"
  echo "  ─────────────────────────────────────────────────"
  echo "  This machine   http://localhost:${PORT}"
  echo "  Other devices  http://${LAN_IP}:${PORT}"
  echo "  Database       ${ROOT}/data/que.sqlite"
  echo
  echo "  Everything works over plain HTTP on every device."
  echo "  For the installable app and offline caching on phones and tablets,"
  echo "  stop this and run:  ./serve.sh --https"
  echo
  echo "  Stop with Ctrl-C."
  echo
  exec env PHP_CLI_SERVER_WORKERS=6 php -S "0.0.0.0:${PORT}" -t "$ROOT"
fi

# -------------------------------------------------------------- https ------
if ! command -v openssl >/dev/null 2>&1; then
  echo "openssl is required to create the local certificate." >&2
  exit 1
fi
if ! php -r 'exit(extension_loaded("openssl") ? 0 : 1);'; then
  echo "PHP is built without the openssl extension; --https is unavailable." >&2
  exit 1
fi
if ! php -r 'exit(extension_loaded("pcntl") ? 0 : 1);'; then
  echo "PHP is built without pcntl; --https is unavailable." >&2
  exit 1
fi

CERT_DIR="$ROOT/data/tls"
CERT="$CERT_DIR/cert.pem"
KEY="$CERT_DIR/key.pem"
STAMP="$CERT_DIR/issued-for"
mkdir -p "$CERT_DIR"
chmod 700 "$CERT_DIR"

# Reissue when the certificate is missing or the machine's LAN address changed,
# since the address has to appear in the certificate for browsers to accept it.
if [ ! -f "$CERT" ] || [ ! -f "$KEY" ] || [ "$(cat "$STAMP" 2>/dev/null || true)" != "$LAN_IP" ]; then
  echo "  Issuing a local certificate for ${LAN_IP} ..."
  openssl req -x509 -newkey rsa:2048 -sha256 -nodes \
    -keyout "$KEY" -out "$CERT" -days 825 \
    -subj "/CN=KAMRYNNE QUE (local)" \
    -addext "subjectAltName=IP:${LAN_IP},IP:127.0.0.1,DNS:localhost" \
    -addext "basicConstraints=critical,CA:TRUE" \
    -addext "keyUsage=digitalSignature,keyEncipherment,keyCertSign" \
    >/dev/null 2>&1
  chmod 600 "$KEY"
  echo "$LAN_IP" > "$STAMP"
fi

FPR=$(openssl x509 -in "$CERT" -noout -fingerprint -sha256 | sed 's/.*=//')

cleanup() {
  [ -n "${BACKEND_PID:-}" ] && kill "$BACKEND_PID" 2>/dev/null || true
  [ -n "${PROXY_PID:-}" ] && kill "$PROXY_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

# Backend listens on loopback only; the proxy is the public face.
env PHP_CLI_SERVER_WORKERS=6 php -S "127.0.0.1:${PORT}" -t "$ROOT" >/dev/null 2>&1 &
BACKEND_PID=$!
sleep 1

php "$ROOT/bin/tls-proxy.php" "$TLS_PORT" "$PORT" "$CERT" "$KEY" &
PROXY_PID=$!
sleep 1

echo
echo "  KAMRYNNE QUE — running locally over HTTPS, no internet needed"
echo "  ────────────────────────────────────────────────────────────"
echo "  This machine   https://localhost:${TLS_PORT}"
echo "  Other devices  https://${LAN_IP}:${TLS_PORT}"
echo "  Database       ${ROOT}/data/que.sqlite"
echo
echo "  The certificate is self-signed. Clicking through the browser warning is"
echo "  NOT enough — browsers refuse offline caching and install on an origin"
echo "  with an untrusted certificate. Install and trust it on each device,"
echo "  once. Step-by-step per platform: docs/DEVICES.md"
echo
echo "  Certificate to install:  data/tls/cert.pem"
echo "  Serve it to a device at: https://${LAN_IP}:${TLS_PORT}/cert.php"
echo
echo "  Certificate SHA-256"
echo "  ${FPR}"
echo
echo "  Stop with Ctrl-C."
echo

wait $PROXY_PID
