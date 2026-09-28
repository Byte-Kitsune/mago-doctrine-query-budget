#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
mago_bin=${MAGO_BIN:-"$root/vendor/bin/mago"}
autoload=${MAGO_VENDOR_AUTOLOAD:-"$root/vendor/autoload.php"}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM

cp "$root/tests/corpus/mago.toml" "$work/mago.toml"
cp -R "$root/tests/corpus/src" "$work/src"
mkdir "$work/src/Many"
cat > "$work/worker.php" <<'PHP'
<?php

declare(strict_types=1);

use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Worker;

require getenv('MAGO_VENDOR_AUTOLOAD');

(new Worker(QueryBudgetExtension::create(
    classBindings: ['App\ServiceInterface' => 'App\Service'],
    warningThreshold: 3,
    errorThreshold: 10,
)))->run();
PHP

php -r '
    $directory = $argv[1];
    for ($index = 0; $index < 20_000; ++$index) {
        $name = sprintf("F%05d", $index);
        file_put_contents("$directory/$name.php", "<?php\nnamespace App\\Many;\nfinal class $name { public function run(): int { return $index; } }\n");
    }
' "$work/src/Many"

cd "$work"
export MAGO_VENDOR_AUTOLOAD=$autoload
"$mago_bin" analyze --reporting-format json --minimum-report-level warning > "$work/report.json" || test "$?" -eq 1
php -r '
    $report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($report["issues"], "code");
    if (count($codes) > 32 || !in_array("byte-kitsune/doctrine-query-budget/query-budget-exceeded", $codes, true)) {
        fwrite(STDERR, "Unexpected query-budget result above 20,000 PHP source files: " . count($codes) . " issues\n");
        exit(1);
    }
' "$work/report.json"

php -r '
    $directory = $argv[1];
    for ($index = 20_000; $index < 25_000; ++$index) {
        $name = sprintf("F%05d", $index);
        file_put_contents("$directory/$name.php", "<?php\nnamespace App\\Many;\nfinal class $name { public function run(): int { return $index; } }\n");
    }
' "$work/src/Many"

if "$mago_bin" analyze --reporting-format json --minimum-report-level warning > "$work/overflow.json" 2> "$work/overflow.err"; then
    echo 'Expected source-count rejection above 25,000 PHP source files' >&2
    exit 1
fi
php -r '
    if (!str_contains(file_get_contents($argv[1]), "PHP source count exceeds query-budget limit of 25000.")) {
        fwrite(STDERR, "Missing explicit source-count rejection\n");
        exit(1);
    }
' "$work/overflow.err"

echo 'Mago query-budget 20k-source and over-limit checks passed'
