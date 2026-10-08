<?php

declare(strict_types=1);

$reports = json_decode(file_get_contents('/tmp/mago-doctrine-file-inspection.json'), true, 512, JSON_THROW_ON_ERROR);
$methods = static function (array $report): array {
    $indexed = [];
    foreach ($report['methods'] as $method) $indexed[$method['symbol']] = $method;
    return $indexed;
};
$service = $methods($reports['src/Service.php']);
$inspection = $methods($reports['src/InspectionService.php']);
$cycles = $methods($reports['src/CycleController.php']);
foreach (['App\Service::__construct' => [0, 0], 'App\Service::run' => [2, 2]] as $symbol => $bounds) {
    if ([$service[$symbol]['lowerBound'], $service[$symbol]['upperBound']] !== $bounds) throw new RuntimeException('Incorrect ordinary service estimate: ' . $symbol);
}
foreach (['App\InspectionService::internal' => [1, 1], 'App\InspectionService::run' => [3, 3]] as $symbol => $bounds) {
    if ([$inspection[$symbol]['lowerBound'], $inspection[$symbol]['upperBound']] !== $bounds) throw new RuntimeException('Incorrect private/transitive estimate: ' . $symbol);
}
foreach (['App\InspectionService::uncertain', 'App\AbstractInspectionService::run'] as $symbol) {
    if ($inspection[$symbol]['upperBound'] !== null || $inspection[$symbol]['unknown'] === []) throw new RuntimeException('Missing unknown implementation evidence.');
}
if ($reports['src/Service.php']['status'] !== 'complete' || $reports['src/InspectionService.php']['status'] !== 'incomplete'
    || $reports['src/Missing.php']['status'] !== 'unsupported' || $reports['missingMethod']['status'] !== 'unsupported'
    || $reports['overridden']['methods'][0]['upperBound'] !== 0
    || count($reports['selected']['methods']) !== 1 || $reports['selected']['methods'][0]['symbol'] !== 'App\InspectionService::internal'
    || $cycles['App\CycleController::recurse']['upperBound'] !== null || $cycles['App\CycleController::recurse']['cycles'] === []) {
    throw new RuntimeException('File/method inspection completeness is incorrect.');
}
foreach (['src/Service.php', 'src/InspectionService.php', 'src/CycleController.php', 'src/Missing.php'] as $file) {
    if ($reports['batch'][$file] !== $reports[$file]) throw new RuntimeException('Batch inspection differs from single-file result: ' . $file);
    if ($reports['snapshot'][$file] !== $reports[$file]) throw new RuntimeException('Snapshot inspection differs from single-file result: ' . $file);
}
if ($reports['emptyBatch'] !== [] || $reports['overriddenBatch']['src/OverrideController.php']['methods'][0]['upperBound'] !== 0) throw new RuntimeException('Batch bindings or empty request changed semantics.');
if ($reports['emptySnapshot'] !== [] || $reports['overriddenSnapshot'] !== $reports['overriddenBatch']
    || $reports['boundSnapshot']['src/ReportController.php'] !== $reports['boundSingle']
    || $methods($reports['boundSingle'])['App\\ReportController::index']['upperBound'] !== 4) throw new RuntimeException('Snapshot bindings or empty request changed semantics.');
$normalized = $reports['normalizedSnapshot']['src\\Service.php'];
foreach ($normalized['methods'] as &$method) $method['path'] = 'src/Service.php';
unset($method);
if ($normalized !== $reports['src/Service.php']) throw new RuntimeException('Normalized snapshot path changed semantics.');
echo "Public file and method inspection passed\n";
