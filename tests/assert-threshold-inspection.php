<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;

$inspect = static fn(string $code): array => QueryBudgetExtension::inspectThresholds('<?php ' . $code);
$cases = [
    ['use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension as Budget; return Budget::create(warningThreshold: 12, errorThreshold: 40, incompleteIssueLimit: 900);', 12, 40],
    ['return \ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension::create([], ["Controller.php"], 11, 35, [], 2);', 11, 35],
    ['use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; return QueryBudgetExtension::create(incompleteIssueLimit: 400);', 10, 25],
    ['use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; $warning=7; $error=30; return QueryBudgetExtension::create(warningThreshold:$warning,errorThreshold:$error);', 7, 30],
    ['use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; const WARN=8; const ERROR=32; return QueryBudgetExtension::create(warningThreshold:WARN,errorThreshold:ERROR);', 8, 32],
    ['use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; const WARN=7; const warn=11; return QueryBudgetExtension::create(warningThreshold:WARN,errorThreshold:warn);', 7, 11],
    ['namespace Config; use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension as Budget; const WARN=9; return Budget::create(warningThreshold:WARN, errorThreshold:9);', 9, 9],
];
foreach ($cases as [$source, $warning, $error]) {
    $result = $inspect($source);
    if ($result !== ['schemaVersion'=>'1','status'=>'resolved','warning'=>$warning,'error'=>$error]) throw new RuntimeException('Incorrect statically resolved threshold: ' . $source . ' ' . json_encode($result));
}
foreach ([
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; return QueryBudgetExtension::create(warningThreshold:(int)getenv("WARNING"));',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; if (getenv("MODE")) return QueryBudgetExtension::create(errorThreshold:20); return QueryBudgetExtension::create(errorThreshold:50);',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; $warning=5; mutate($warning); return QueryBudgetExtension::create(warningThreshold:$warning);',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; $warning=5; function config($warning) { return QueryBudgetExtension::create(warningThreshold:$warning); }',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; return QueryBudgetExtension::create(...$args);',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; return QueryBudgetExtension::create(warningThreshold:30,errorThreshold:20);',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; $warning=5; return mutate($warning) + QueryBudgetExtension::create(warningThreshold:$warning);',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; function unused() { return QueryBudgetExtension::create(warningThreshold:15,errorThreshold:20); }',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; if(false) return QueryBudgetExtension::create(warningThreshold:15,errorThreshold:20);',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; $unused=fn()=>QueryBudgetExtension::create(warningThreshold:15,errorThreshold:20);',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; class Factory { function unused() { return QueryBudgetExtension::create(warningThreshold:15,errorThreshold:20); } }',
    'use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension; const WARN=10; return QueryBudgetExtension::create(warningThreshold:warn);',
    'return require "external.php";',
    'broken PHP }',
] as $source) {
    $result = $inspect($source);
    if ($result['status'] !== 'unresolved' || isset($result['warning'], $result['error'])) throw new RuntimeException('Unsafe thresholds were guessed.');
}
$sentinel = '/tmp/doctrine-threshold-must-not-execute';
@unlink($sentinel);
$result = $inspect('file_put_contents("' . $sentinel . '", "unsafe"); return \ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension::create();');
if (file_exists($sentinel) || $result['warning'] !== 10 || $result['error'] !== 25) throw new RuntimeException('Configuration code was executed.');
if ($inspect('return Other\QueryBudgetExtension::create();')['status'] !== 'absent') throw new RuntimeException('Unrelated class configuration was mistaken for Doctrine.');
echo "Static threshold inspection passed\n";
