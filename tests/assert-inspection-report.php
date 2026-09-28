<?php

declare(strict_types=1);

$report = json_decode(file_get_contents('/tmp/mago-doctrine-query-budget-inspection.json'), true, 512, JSON_THROW_ON_ERROR);
$issues = [];
foreach ($report['issues'] as $issue) $issues[$issue['code']][] = $issue;
$prefix = 'byte-kitsune/doctrine-query-budget/';
$inspections = $issues[$prefix . 'query-budget-inspection'] ?? [];
if (count($inspections) !== 3) throw new RuntimeException('Expected exactly three focused inspections.');
$bounds = [];
foreach ($inspections as $issue) {
    $note = $issue['notes'][0] ?? '';
    if (!str_starts_with($note, 'query-budget-evidence: ')) throw new RuntimeException('Inspection evidence missing.');
    $evidence = json_decode(substr($note, strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
    $bounds[$evidence['entrypoint']] = [$evidence['lower_bound'], $evidence['upper_bound']];
}
if (($bounds['App\ReportController::index'] ?? null) !== [4, 4]
    || ($bounds['App\BuiltinController::index'] ?? null) !== [0, 0]
    || ($bounds['App\UnqualifiedBuiltinController::index'] ?? null) !== [0, null]) {
    throw new RuntimeException('Focused bounds are incorrect.');
}
if (isset($issues[$prefix . 'query-budget-incomplete']) || count($issues[$prefix . 'query-budget-incomplete-summary'] ?? []) !== 1
    || count($issues[$prefix . 'inspection-target-not-found'] ?? []) !== 1) {
    throw new RuntimeException('Incomplete findings were not condensed or missing target was hidden.');
}
$summary = json_decode(substr($issues[$prefix . 'query-budget-incomplete-summary'][0]['notes'][0], strlen('query-budget-summary: ')), true, 512, JSON_THROW_ON_ERROR);
if ($summary !== ['schema_version' => '1', 'incomplete_entrypoints' => 1, 'reported_entrypoints' => 0, 'omitted_entrypoints' => 1]) {
    throw new RuntimeException('Incomplete summary count is incorrect.');
}
$attestation = json_decode(substr($issues[$prefix . 'analysis-attestation'][0]['notes'][0], strlen('extension-attestation: ')), true, 512, JSON_THROW_ON_ERROR);
foreach (['entrypoints_analyzed' => 3, 'incomplete_entrypoints' => 1, 'reported_incomplete_entrypoints' => 0, 'omitted_incomplete_entrypoints' => 1, 'requested_inspections' => 4, 'matched_inspections' => 3] as $key => $expected) {
    if (($attestation[$key] ?? null) !== $expected) throw new RuntimeException('Incorrect attestation field: ' . $key);
}
echo "Focused inspection and incomplete summary passed\n";
