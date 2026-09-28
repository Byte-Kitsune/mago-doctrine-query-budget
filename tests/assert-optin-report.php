<?php

declare(strict_types=1);

$report = json_decode(file_get_contents('/tmp/mago-doctrine-query-budget-optin.json'), true, 512, JSON_THROW_ON_ERROR);
$bounds = [];
$unknowns = [];
foreach ($report['issues'] as $issue) {
    if ($issue['code'] !== 'byte-kitsune/doctrine-query-budget/query-budget-inspection') continue;
    $note = $issue['notes'][0] ?? '';
    $evidence = json_decode(substr($note, strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
    $bounds[$evidence['entrypoint']] = [$evidence['lower_bound'], $evidence['upper_bound']];
    $unknowns[$evidence['entrypoint']] = $evidence['unknown'];
}
if (($bounds['App\UnqualifiedBuiltinController::index'] ?? null) !== [0, 0]
    || ($bounds['App\Shadow\ShadowBuiltinController::index'] ?? null) !== [1, 1]
    || ($bounds['GlobalBuiltinController::index'] ?? null) !== [0, 0]
    || ($bounds['GlobalAliasController::index'] ?? null) !== [0, null]
    || ($unknowns['App\UnqualifiedBuiltinController::index'] ?? null) !== []) {
    throw new RuntimeException('Scalar builtin opt-in or namespaced override was not resolved correctly.');
}
echo "Global scalar builtin opt-in passed\n";
