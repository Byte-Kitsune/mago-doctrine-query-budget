<?php

declare(strict_types=1);

$report = json_decode(file_get_contents('/tmp/mago-doctrine-query-budget-report.json'), true, 512, JSON_THROW_ON_ERROR);
$functionBudget = null;
$functionUnknown = null;
$externalUnknown = null;
$guardedUnknown = null;
$guardedCycle = null;
$functionCycle = null;
foreach ($report['issues'] as $issue) {
    if ($issue['code'] === 'byte-kitsune/doctrine-query-budget/query-budget-exceeded'
        && str_contains($issue['notes'][0] ?? '', 'FunctionController::index')
        && !str_contains($issue['notes'][0] ?? '', 'UnknownFunctionController::index')) {
        $functionBudget = $issue;
    }
    if ($issue['code'] === 'byte-kitsune/doctrine-query-budget/query-budget-incomplete' && str_contains($issue['message'], 'App\\FunctionController::index')) {
        $functionUnknown = $issue;
    }
    if ($issue['code'] === 'byte-kitsune/doctrine-query-budget/query-budget-incomplete' && str_contains($issue['message'], 'App\\UnknownFunctionController::index')) {
        $externalUnknown = $issue;
    }
    if ($issue['code'] === 'byte-kitsune/doctrine-query-budget/query-budget-incomplete' && str_contains($issue['message'], 'App\\GuardedFunctionController::index')) {
        $guardedUnknown = $issue;
    }
    if ($issue['code'] === 'byte-kitsune/doctrine-query-budget/recursive-call' && str_contains($issue['message'], 'App\\GuardedFunctionController::index')) {
        $guardedCycle = $issue;
    }
    if ($issue['code'] === 'byte-kitsune/doctrine-query-budget/recursive-call' && str_contains($issue['message'], 'App\\CyclicFunctionController::index')) {
        $functionCycle = $issue;
    }
}
if ($functionBudget === null || $functionUnknown !== null || $externalUnknown === null || $guardedUnknown === null || $guardedCycle !== null || $functionCycle === null) {
    throw new RuntimeException('Project function calls were not evaluated or unknown external calls were hidden.');
}
$budget = json_decode(substr($functionBudget['notes'][0], strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
$unknown = json_decode(substr($externalUnknown['notes'][0], strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
if ($budget['lower_bound'] !== 4 || $budget['upper_bound'] !== 4 || $unknown['upper_bound'] !== null || !str_contains(implode(' ', $unknown['unknown']), 'json_encode')) {
    throw new RuntimeException('Function-call evidence did not preserve the expected bounds and name.');
}
echo "Function-call corpus passed\n";
