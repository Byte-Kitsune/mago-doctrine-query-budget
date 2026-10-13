<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new \Mago\Sdk\Worker(\ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension::create(hookEntrypoints: ['HookExamples\\ConfiguredService::hiddenHandler'])))->run();
