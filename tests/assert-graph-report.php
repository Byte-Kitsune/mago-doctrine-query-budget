<?php

declare(strict_types=1);

$report = json_decode(file_get_contents('/tmp/mago-doctrine-query-budget-report.json'), true, 512, JSON_THROW_ON_ERROR);
$evidence = [];
foreach ($report['issues'] as $issue) {
    foreach ($issue['notes'] ?? [] as $note) {
        if (!str_starts_with($note, 'query-budget-evidence: ')) continue;
        $data = json_decode(substr($note, strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
        $evidence[$data['entrypoint']] = $data;
    }
}
foreach (['App\\TraitController::index', 'App\\ScopedController::index'] as $entrypoint) {
    $found = $evidence[$entrypoint] ?? null;
    if ($found === null || $found['lower_bound'] !== 4 || $found['upper_bound'] !== 4 || $found['unknown'] !== []) {
        throw new RuntimeException('Source-visible trait or scoped call was not followed: ' . $entrypoint);
    }
}
$adapted = $evidence['App\\AdaptedController::index'] ?? null;
if ($adapted === null || $adapted['upper_bound'] !== null || $adapted['unknown'] === []) {
    throw new RuntimeException('Unmodeled trait adaptation was treated as complete.');
}
$guard = $evidence['App\\GuardController::index'] ?? null;
if ($guard === null || $guard['lower_bound'] !== 0 || $guard['upper_bound'] !== 4 || $guard['unknown'] !== []) {
    throw new RuntimeException('Conditional early return lost a finite upper bound.');
}
echo "Trait and scoped-call corpus passed\n";
