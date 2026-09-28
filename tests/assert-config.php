<?php

declare(strict_types=1);

use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;

require dirname(__DIR__) . '/vendor/autoload.php';

foreach ([
    ['inspectEntrypoints' => ['App\Controller;bad']],
    ['inspectEntrypoints' => ['App\ReportController', '\App\ReportController']],
    ['inspectEntrypoints' => array_fill(0, 33, 'App\ReportController')],
    ['incompleteIssueLimit' => -1],
    ['incompleteIssueLimit' => 1001],
] as $options) {
    try {
        QueryBudgetExtension::create(...$options);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Invalid query-budget configuration was accepted.');
}
echo "Query-budget configuration validation passed\n";
