<?php

declare(strict_types=1);

$report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$inspections = []; $cycles = [];
foreach ($report['issues'] as $issue) {
    if (!str_starts_with($issue['code'], 'byte-kitsune/doctrine-query-budget/')) continue;
    foreach ($issue['notes'] ?? [] as $note) {
        if (!str_starts_with($note, 'query-budget-evidence: ')) continue;
        $value = json_decode(substr($note, strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
        if (str_ends_with($issue['code'], '/query-budget-inspection')) $inspections[$value['entrypoint']] = $value;
        if (str_ends_with($issue['code'], '/recursive-call')) $cycles[] = $value;
    }
}
if (count($inspections) !== 13 || count($cycles) !== 1 || $cycles[0]['entrypoint'] !== 'Example\\RealCycleController::getDetails') throw new RuntimeException('Forwarding and real-cycle entrypoint selection changed.');
foreach ($inspections as $entrypoint => $estimate) {
    if ($entrypoint === 'Example\\RealCycleController::getDetails') continue;
    if ($estimate['lower_bound'] !== 4 || $estimate['upper_bound'] !== 4 || $estimate['cycles'] !== [] || $estimate['unknown'] !== []) throw new RuntimeException('Same-name forwarding was misresolved: ' . $entrypoint);
}
echo "Twelve typed forwarding controllers and the real cycle control passed\n";
