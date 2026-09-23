#!/usr/bin/env bash
# Alle Prüfungen ohne Symcon: Syntax, JSON, Regressionstests. Aus dem Repo-Ordner: tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/.."
for f in libs/*.php EOS*/module.php tests/*.php; do php -l "$f" >/dev/null; done
for f in EOS*/*.json library.json; do python3 -m json.tool "$f" >/dev/null; done
bash -n .docker/setup.sh
echo "Syntax und JSON in Ordnung."
php tests/control_test.php | tail -1
php tests/control_review_test.php | tail -1
php tests/sync_meter_test.php | tail -1
php tests/server_test.php | tail -1
php tests/config_test.php | tail -1
