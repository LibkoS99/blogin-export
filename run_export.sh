#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ -f "$SCRIPT_DIR/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  . "$SCRIPT_DIR/.env"
  set +a
fi

if [ -f "$SCRIPT_DIR/.env.local" ]; then
  set -a
  # shellcheck disable=SC1091
  . "$SCRIPT_DIR/.env.local"
  set +a
fi

if [ -z "${BLOGIN_API_KEY:-}" ]; then
  echo "Error: BLOGIN_API_KEY is not set. Create $SCRIPT_DIR/.env or $SCRIPT_DIR/.env.local, or export it." >&2
  exit 1
fi

if [ "$#" -eq 0 ]; then
  set -- --previous-quarter
fi

php "$SCRIPT_DIR/blogin_export.php" "$@"
