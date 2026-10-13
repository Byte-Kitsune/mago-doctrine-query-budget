<?php

declare(strict_types=1);
use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Worker;
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Worker(QueryBudgetExtension::create(
    warningThreshold: 100,
    errorThreshold: 200,
    inspectEntrypoints: [
        'Example\\Flow01Controller::get',
        'Example\\Flow02Controller::handle',
        'Example\\Flow03Controller::getFooter',
        'Example\\Flow04Controller::getDetails',
        'Example\\Flow05Controller::submit',
        'Example\\Flow06Controller::download',
        'Example\\Flow07Controller::get',
        'Example\\Flow08Controller::handle',
        'Example\\Flow09Controller::getFooter',
        'Example\\Flow10Controller::getDetails',
        'Example\\Flow11Controller::submit',
        'Example\\Flow12Controller::download',
        'Example\\RealCycleController::getDetails'
    ],
)))->run();
