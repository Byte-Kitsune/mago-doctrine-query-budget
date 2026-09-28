<?php

declare(strict_types=1);

use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Worker(QueryBudgetExtension::create(
    classBindings: ['App\ServiceInterface' => 'App\Service'],
    constructorBindings: [
        'App\OverrideController' => [0 => 'App\PureService'],
        'App\AssignedController' => [0 => 'App\PureService'],
    ],
    warningThreshold: 3,
    errorThreshold: 10,
    inspectEntrypoints: [
        'App\\ReportCommand::execute',
        'App\\OverrideController::index',
        'App\\AssignedController::index',
        'App\\ArrayController::index',
        'App\ReportController::index',
        'App\BuiltinController',
        'App\UnqualifiedBuiltinController::index',
        'App\MissingController::index',
    ],
    incompleteIssueLimit: 0,
)))->run();
