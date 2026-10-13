<?php

declare(strict_types=1);
$report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$evidence = []; $cycles = [];
foreach ($report['issues'] as $issue) foreach ($issue['notes'] ?? [] as $note) {
    if (!str_starts_with($note, 'query-budget-evidence: ')) continue;
    $value = json_decode(substr($note, strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
    $method = substr($value['entrypoint'], strrpos($value['entrypoint'], '::') + 2);
    if (str_ends_with($issue['code'], '/query-budget-inspection')) $evidence[$method] = $value;
    if (str_ends_with($issue['code'], '/recursive-call')) $cycles[] = $method;
}
$expected = [];
foreach (['create', 'emptyMap', 'unrelated', 'override', 'scalarCallbacks', 'calleeBeforeArgument', 'mapCalleeBeforeArgument', 'fromCallableCreation', 'inheritedOverride'] as $method) $expected[$method] = [0, 0];
foreach (['variable', 'captured', 'arrow', 'immediate', 'forwarding', 'namedForwarding', 'arrayTarget', 'stringTarget', 'invokable', 'callUser', 'callArray', 'pipe', 'overwritten', 'inherited', 'statement', 'dbalBuilder', 'defaultCallback', 'returnedCallback', 'callbackInvoke', 'queryCalleeBeforeArgument', 'fromCallable'] as $method) $expected[$method] = [1, 1];
foreach (['byReference', 'invalidated', 'branched', 'recursiveTree', 'deferred', 'cache', 'orm', 'unknownMap', 'opaqueScalar', 'directCycle', 'referenceParameter', 'referenceWalk', 'tryBinding', 'foreachBinding', 'mutatedArray', 'referenceAlias', 'referenceLoop', 'transaction', 'transactionCallback', 'ormTransaction', 'unitOfWork'] as $method) $expected[$method] = [0, null];
$expected += ['mapQueryBeforeArgument' => [2, 2], 'map' => [3, 3], 'namedMap' => [2, 2], 'storedArray' => [2, 2], 'shortCircuit' => [0, 3], 'constants' => [3, 3], 'external' => [1, null], 'nullsafeInvoke' => [0, 1], 'effectfulInvocation' => [1, null]];
if (count($expected) !== count($evidence)) throw new RuntimeException('Missing or unexpected callback inspection: ' . json_encode(array_keys($evidence)));
foreach ($expected as $method => $bounds) {
    $value = $evidence[$method] ?? null;
    if ($value === null || [$value['lower_bound'], $value['upper_bound']] !== $bounds) throw new RuntimeException('Unexpected callback budget for ' . $method . ': ' . json_encode($value));
    if ($bounds[1] === null && $value['unknown'] === []) throw new RuntimeException('Missing uncertainty evidence for ' . $method);
    if ($bounds[1] !== null && $value['unknown'] !== []) throw new RuntimeException('Unexpected uncertainty for ' . $method);
}
if ($cycles !== ['directCycle']) throw new RuntimeException('Deferred or traversal callback was reported as an unconditional cycle.');
if (!str_contains(implode(' ', $evidence['orm']['unknown']), 'ORM cache')) throw new RuntimeException('Doctrine interface/factory provenance was lost.');
if (!str_contains(implode(' ', $evidence['external']['unknown']), 'SearchClient\\Query')) throw new RuntimeException('Opaque external effect was not preserved.');
if (!str_contains(implode(' ', $evidence['transactionCallback']['unknown']), 'callback recursion')) throw new RuntimeException('Transactional callback was not followed.');
if (!str_contains(implode(' ', $evidence['unitOfWork']['unknown']), 'UnitOfWork')) throw new RuntimeException('ORM UnitOfWork provenance was lost.');
echo count($expected) . " callback invocation and Doctrine provenance budgets passed\n";
