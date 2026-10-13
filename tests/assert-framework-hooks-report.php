<?php
declare(strict_types=1);
$report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$found = [];
foreach ($report['issues'] as $issue) if (str_ends_with($issue['code'], '/query-budget-hook-inspection')) {
    $value = json_decode(substr($issue['notes'][0], strlen('query-budget-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
    $found[$value['entrypoint']] = $value;
}
$expected = ['ArrivalService::receive' => 1, 'DefaultListener::onKernelRequest' => 0, 'DefaultListener::__invoke' => 0, 'MessageService::process' => 1, 'Subscriber::receive' => 0, 'Subscriber::finish' => 0, 'Subscriber::first' => 0, 'Subscriber::last' => 0, 'WorkerMiddleware::handle' => 0, 'Validation::validate' => 1, 'DatabaseListener::postLoad' => 1, 'EntityListener::afterWrite' => 0, 'Entity::loaded' => 0, 'LanguageHooks::__serialize' => 1, 'LanguageHooks::__toString' => 0, 'ConfiguredService::hiddenHandler' => 1];
if (count($found) !== count($expected)) throw new RuntimeException('Unexpected framework hooks: ' . json_encode(array_keys($found)));
foreach ($expected as $target => $queries) {
    $entry = $found['HookExamples\\' . $target] ?? null;
    if ($entry === null || [$entry['lower_bound'], $entry['upper_bound']] !== [$queries, $queries]) throw new RuntimeException('Wrong per-invocation budget: ' . $target . ' ' . json_encode($entry));
    foreach ($entry['hook_candidates'] as $hook) if (!in_array($hook['activation'], ['declaration-only', 'registration-only'], true) || $hook['budgetScope'] !== 'one-invocation') throw new RuntimeException('Declaration was represented as activated dispatch.');
}
foreach ($report['issues'] as $issue) if (str_ends_with($issue['code'], '/framework-hook-discovery-incomplete')) throw new RuntimeException('Hook resolution unexpectedly incomplete: ' . json_encode($issue));
echo count($expected) . " Symfony/Doctrine/language hook invocation budgets passed\n";
