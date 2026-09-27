<?php

declare(strict_types=1);

use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Worker(QueryBudgetExtension::create(
    classBindings: ['App\ReportServiceInterface' => 'App\ReportService'],
    warningThreshold: 3,
    errorThreshold: 10,
)))->run();
