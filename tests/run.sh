#!/usr/bin/env sh
# Tüm testleri yerelde çalıştırır: ./tests/run.sh
set -e
cd "$(dirname "$0")/.."

echo "== PHP lint =="
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l >/dev/null

echo "== JS syntax =="
for f in assets/*.js tests/*.js tools/*.mjs; do node --check "$f"; done
if command -v python3 >/dev/null 2>&1; then python3 -m py_compile tools/make-engine-manifest.py; fi

echo "== PHP tests =="
for f in tests/test_*.php; do echo "-- $f"; php "$f"; done

echo "== JS tests =="
node tests/audio.test.js
node tests/piper.test.js

echo "All tests passed."
