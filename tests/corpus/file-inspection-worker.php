<?php

declare(strict_types=1);

use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class FileInspectionHook implements AfterAnalysisHook
{
    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        foreach ([['file' => ''], ['file' => 'src/Service.php', 'method' => 'bad method'], ['file' => "src/Service.php\0"]] as $options) {
            try {
                QueryBudgetExtension::inspectFile($context->analysis, ...$options);
            } catch (InvalidArgumentException) {
                continue;
            }
            throw new RuntimeException('Invalid file inspection selector was accepted.');
        }
        $reports = [];
        foreach (['src/Service.php', 'src/InspectionService.php', 'src/CycleController.php', 'src/Missing.php'] as $file) {
            $reports[$file] = QueryBudgetExtension::inspectFile($context->analysis, $file);
        }
        $reports['overridden'] = QueryBudgetExtension::inspectFile($context->analysis, 'src/OverrideController.php', constructorBindings: ['App\OverrideController' => [0 => 'App\PureService']], method: 'App\OverrideController::index');
        $reports['selected'] = QueryBudgetExtension::inspectFile($context->analysis, 'src/InspectionService.php', method: 'App\InspectionService::internal');
        $reports['missingMethod'] = QueryBudgetExtension::inspectFile($context->analysis, 'src/InspectionService.php', method: 'App\InspectionService::missing');
        file_put_contents('/tmp/mago-doctrine-file-inspection.json', json_encode($reports, JSON_THROW_ON_ERROR));
    }
}

final class FileInspectionPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('byte-kitsune/file-inspection-test', 'File inspection test', 'Checks the public file inspection API.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook(new FileInspectionHook());
    }
}

(new Worker(new Extension('byte-kitsune/file-inspection-test', 'File inspection test', '1', analyzerPlugins: [new FileInspectionPlugin()])))->run();
