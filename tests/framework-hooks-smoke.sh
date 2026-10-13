#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
output=$(mktemp -d)
trap 'rm -rf "$output"' EXIT HUP INT TERM
cd "$root/tests/framework-hooks-corpus"
"${MAGO_BIN:-$root/vendor/bin/mago}" analyze --reporting-format json --minimum-report-level note > "$output/report.json" || test "$?" -eq 1
php "$root/tests/assert-framework-hooks-report.php" "$output/report.json"
