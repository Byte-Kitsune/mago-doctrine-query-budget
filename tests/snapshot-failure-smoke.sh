#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
mago_bin=${MAGO_BIN:-"$root/vendor/bin/mago"}
autoload=${MAGO_VENDOR_AUTOLOAD:-"$root/vendor/autoload.php"}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM
cp "$root/tests/corpus/mago-file-inspection.toml" "$work/mago.toml"
cp "$root/tests/corpus/snapshot-failure-worker.php" "$work/file-inspection-worker.php"
mkdir "$work/src"
php -r '
    $source = "<?php final class Oversized {\n";
    for ($i = 0; $i < 513; ++$i) $source .= "public function m$i(): int { return 0; }\n";
    file_put_contents("$argv[1]/Oversized.php", $source . "}\n");
    file_put_contents("$argv[1]/Healthy.php", "<?php final class Healthy { public function run(): int { return 0; } }\n");
    file_put_contents("$argv[1]/LargeSource.php", "<?php /*" . str_repeat("x", 1048576) . "*/\n");
' "$work/src"
cd "$work"
export MAGO_VENDOR_AUTOLOAD=$autoload
export MAGO_INSPECTION_FAILURE_OUTPUT="$work/result.txt"
"$mago_bin" analyze --reporting-format json --minimum-report-level warning > "$work/report.json" || test "$?" -eq 1
test -s "$work/result.txt"
cat "$work/result.txt"
