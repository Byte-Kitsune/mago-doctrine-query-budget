<?php

declare(strict_types=1);

$report = json_decode(file_get_contents('/tmp/mago-doctrine-query-budget-report.json'), true, 512, JSON_THROW_ON_ERROR);
$incomplete = [];
$evidence = [];
foreach ($report['issues'] as $issue) {
    if ($issue['code'] === 'byte-kitsune/doctrine-query-budget/query-budget-incomplete') {
        $incomplete[] = $issue['message'];
    }
    foreach ($issue['notes'] ?? [] as $note) {
        if (!str_starts_with($note, 'query-budget-evidence: ')) continue;
        $data = json_decode(substr($note, strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
        $evidence[$data['entrypoint']] = $data;
    }
}
foreach (['App\\TryController::index', 'App\\SearchOnlyController::index', 'App\\BuiltinController::index'] as $entrypoint) {
    foreach ($incomplete as $message) {
        if (str_contains($message, $entrypoint)) throw new RuntimeException('A modeled try/catch was marked incomplete: ' . $entrypoint);
    }
}
$unqualified = $evidence['App\\UnqualifiedBuiltinController::index'] ?? null;
$object = $evidence['App\\ObjectBuiltinController::index'] ?? null;
$unknownTry = $evidence['App\\UnknownTryController::index'] ?? null;
if ($unqualified === null || !str_contains(implode(' ', $unqualified['unknown']), 'max')
    || $object === null || !str_contains(implode(' ', $object['unknown']), 'max')
    || $unknownTry === null || !str_contains(implode(' ', $unknownTry['unknown']), 'json_encode')) {
    throw new RuntimeException('Unproven builtin calls were hidden.');
}
$try = $evidence['App\\TryController::index'] ?? null;
if ($try === null || $try['lower_bound'] !== 0 || $try['upper_bound'] !== 4 || $try['unknown'] !== []) {
    throw new RuntimeException('Try/catch/finally query bound was not conservative and finite.');
}
echo "Try/catch corpus passed\n";
