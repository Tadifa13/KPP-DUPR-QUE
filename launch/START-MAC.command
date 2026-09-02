#!/usr/bin/env bash
# ===========================================================================
#  KAMRYNNE QUE — double-click to run.
#  Starts the app on this Mac and opens it in your browser. No internet needed.
#
#  If macOS refuses to open it: right-click → Open, then confirm once.
# ===========================================================================
cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

if ! command -v php >/dev/null 2>&1; then
  echo
  echo "  PHP is not installed. Run this once, then try again:"
  echo
  echo "      xcode-select --install"
  echo
  read -r -p "  Press Return to close." _
  exit 1
fi

# Open the browser once the server is actually accepting connections.
( for _ in $(seq 1 40); do
    if curl -s -o /dev/null http://127.0.0.1:8080/ 2>/dev/null; then
      open http://localhost:8080; break
    fi
    sleep 0.3
  done ) &

exec bash ./serve.sh
