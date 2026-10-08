#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
mago_bin=${MAGO_BIN:-"$root/vendor/bin/mago"}
autoload=${MAGO_VENDOR_AUTOLOAD:-"$root/vendor/autoload.php"}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM

sed 's/"php", /"php", "-d", "memory_limit=2G", /' "$root/tests/corpus/mago-file-inspection.toml" > "$work/mago.toml"
cp "$root/tests/corpus/snapshot-benchmark-worker.php" "$work/file-inspection-worker.php"
mkdir "$work/src"
php -r '
    $count = (int) ($argv[2] ?? 20000);
    if ($count < 2001 || $count > 25000) throw new InvalidArgumentException("Expected 2001..25000 sources.");
    for ($index = 0; $index < $count; ++$index) {
        $name = sprintf("F%05d", $index);
        file_put_contents("$argv[1]/$name.php", "<?php\nnamespace App\\Many;\nfinal class $name { public function run(): int { return $index; } }\n");
    }
' "$work/src" "${MAGO_INSPECTION_BENCHMARK_FILES:-20000}"

cd "$work"
export MAGO_VENDOR_AUTOLOAD=$autoload
export MAGO_INSPECTION_BENCHMARK_OUTPUT="$work/benchmark.json"
"$mago_bin" analyze --reporting-format json --minimum-report-level warning > "$work/report.json" || test "$?" -eq 1
test -s "$work/benchmark.json"
cat "$work/benchmark.json"
