<?php

declare(strict_types=1);
$report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$evidence = []; $cycles = [];
foreach ($report['issues'] as $issue) {
    foreach ($issue['notes'] ?? [] as $note) {
        if (!str_starts_with($note, 'query-budget-evidence: ')) continue;
        $value = json_decode(substr($note, strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
        if (str_ends_with($issue['code'], '/query-budget-inspection')) $evidence[$value['entrypoint']] = $value;
        if (str_ends_with($issue['code'], '/recursive-call')) $cycles[] = $value['entrypoint'];
    }
}
if ($cycles !== ['Example\\CallableController::direct'] || count($evidence) !== 6) throw new RuntimeException('Callable references executed a recursive target, or a direct recursive call was missed.');
$reference = $evidence['Example\\CallableController::reference'];
if ($reference['lower_bound'] !== 0 || $reference['upper_bound'] !== 0 || $reference['unknown'] !== [] || $reference['cycles'] !== []) throw new RuntimeException('Creating a callable must not execute its target.');
foreach (['tree', 'staticTree', 'functionTree', 'arrowTree'] as $method) {
    $value = $evidence['Example\\CallableController::' . $method];
    if ($value['cycles'] !== [] || $value['upper_bound'] !== null || $value['unknown'] === []) throw new RuntimeException('Unbounded higher-order callback execution must remain incomplete without fabricated recursive-call evidence.');
}
echo "First-class callback creation, incomplete array_map bounds and direct recursion controls passed\n";
