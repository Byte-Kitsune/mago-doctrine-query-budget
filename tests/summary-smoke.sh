#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM
mkdir "$work/src"
cat > "$work/mago.toml" <<'TOML'
version = "1"
php-version = "8.2"
[source]
paths = ["src"]
[extension-hosts.php]
command = ["php", "worker.php"]
workers = 1
TOML
cat > "$work/worker.php" <<'PHP'
<?php
use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Worker;
require getenv('MAGO_VENDOR_AUTOLOAD');
(new Worker(QueryBudgetExtension::create(incompleteIssueLimit: 2)))->run();
PHP
php -r '
    for ($index = 0; $index < 30; ++$index) {
        $name = sprintf("Unknown%02dController", $index);
        file_put_contents($argv[1] . "/$name.php", "<?php\nnamespace App;\nfinal class $name { public function index(): void { missing_function(); } }\n");
    }
' "$work/src"
cd "$work"
export MAGO_VENDOR_AUTOLOAD=${MAGO_VENDOR_AUTOLOAD:-"$root/vendor/autoload.php"}
"${MAGO_BIN:-"$root/vendor/bin/mago"}" analyze --reporting-format json --minimum-report-level note \
    --retain-code byte-kitsune/doctrine-query-budget/query-budget-incomplete \
    --retain-code byte-kitsune/doctrine-query-budget/query-budget-incomplete-summary \
    --retain-code byte-kitsune/doctrine-query-budget/analysis-attestation > "$work/report.json" || test "$?" -eq 1
php -r '
    $report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $issues = [];
    foreach ($report["issues"] as $issue) $issues[$issue["code"]][] = $issue;
    $prefix = "byte-kitsune/doctrine-query-budget/";
    if (count($issues[$prefix . "query-budget-incomplete"] ?? []) !== 2 || count($issues[$prefix . "query-budget-incomplete-summary"] ?? []) !== 1) exit(1);
    $names = array_map(static function (array $issue): string {
        $note = $issue["notes"][0];
        return json_decode(substr($note, strlen("query-budget-evidence: ")), true, 512, JSON_THROW_ON_ERROR)["entrypoint"];
    }, $issues[$prefix . "query-budget-incomplete"]);
    if ($names !== ["App\\Unknown00Controller::index", "App\\Unknown01Controller::index"]) exit(1);
    $summary = json_decode(substr($issues[$prefix . "query-budget-incomplete-summary"][0]["notes"][0], strlen("query-budget-summary: ")), true, 512, JSON_THROW_ON_ERROR);
    $attestation = json_decode(substr($issues[$prefix . "analysis-attestation"][0]["notes"][0], strlen("extension-attestation: ")), true, 512, JSON_THROW_ON_ERROR);
    if ($summary["incomplete_entrypoints"] !== 30 || $summary["reported_entrypoints"] !== 2 || $summary["omitted_entrypoints"] !== 28 || $attestation["omitted_incomplete_entrypoints"] !== 28) exit(1);
    echo "30-entrypoint incomplete summary passed\n";
' "$work/report.json"
