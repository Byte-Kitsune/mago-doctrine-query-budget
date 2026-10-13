#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
output=$(mktemp -d)
trap 'rm -rf "$output"' EXIT HUP INT TERM
cd "$root/tests/forwarding-corpus"
"${MAGO_BIN:-$root/vendor/bin/mago}" analyze --reporting-format json --minimum-report-level note > "$output/report.json" || test "$?" -eq 1
php "$root/tests/assert-forwarding-report.php" "$output/report.json"
if [ "${MAGO_EXTENSION_BACKEND:-rust}" = rust ]; then
    MAGO_NATIVE_TRACE=1 MAGO_NATIVE_TRACE_FILE="$output/trace.log" "${MAGO_BIN:-$root/vendor/bin/mago}" analyze --reporting-format json --minimum-report-level note > "$output/traced-report.json" || test "$?" -eq 1
    cmp "$output/report.json" "$output/traced-report.json"
    php "$root/tests/assert-recursion-trace.php" "$output/trace.log"
fi
