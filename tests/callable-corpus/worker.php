<?php
use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Worker;
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Worker(QueryBudgetExtension::create(inspectEntrypoints: ['Example\CallableController'])))->run();
