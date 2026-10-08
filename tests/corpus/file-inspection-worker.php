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
        $reports['batch'] = QueryBudgetExtension::inspectFiles($context->analysis, ['src/Service.php', 'src/InspectionService.php', 'src/CycleController.php', 'src/Missing.php']);
        $reports['emptyBatch'] = QueryBudgetExtension::inspectFiles($context->analysis, []);
        $reports['overriddenBatch'] = QueryBudgetExtension::inspectFiles($context->analysis, ['src/OverrideController.php'], constructorBindings: ['App\\OverrideController' => [0 => 'App\\PureService']]);
        $reports['snapshot'] = QueryBudgetExtension::inspectSnapshot($context->analysis, ['src/Service.php', 'src/InspectionService.php', 'src/CycleController.php', 'src/Missing.php']);
        $reports['emptySnapshot'] = QueryBudgetExtension::inspectSnapshot($context->analysis, []);
        $reports['overriddenSnapshot'] = QueryBudgetExtension::inspectSnapshot($context->analysis, ['src/OverrideController.php'], constructorBindings: ['App\\OverrideController' => [0 => 'App\\PureService']]);
        $reports['boundSnapshot'] = QueryBudgetExtension::inspectSnapshot($context->analysis, ['src/ReportController.php'], classBindings: ['App\\ServiceInterface' => 'App\\Service']);
        $reports['boundSingle'] = QueryBudgetExtension::inspectFile($context->analysis, 'src/ReportController.php', classBindings: ['App\\ServiceInterface' => 'App\\Service']);
        $reports['normalizedSnapshot'] = QueryBudgetExtension::inspectSnapshot($context->analysis, ['src\\Service.php']);
        $largeSelection = array_map(static fn(int $index): string => 'src/Missing' . $index . '.php', range(1, 50_000));
        $largeReports = QueryBudgetExtension::inspectSnapshot($context->analysis, $largeSelection);
        if (array_keys($largeReports) !== $largeSelection || array_unique(array_column($largeReports, 'status')) !== ['unsupported']) {
            throw new RuntimeException('The 50000 source selection boundary changed semantics.');
        }
        foreach ([[''], [42], ["src/Service.php\0"], [str_repeat('x', 4097)], ['src/Service.php', 'src\\Service.php'], [...$largeSelection, 'src/Overflow.php']] as $invalid) {
            try { QueryBudgetExtension::inspectSnapshot($context->analysis, $invalid); }
            catch (InvalidArgumentException) { continue; }
            throw new RuntimeException('Invalid snapshot inspection selector was accepted.');
        }
        foreach ([[''], ['src/Service.php', 'src\\Service.php'], array_fill(0, 2001, 'src/Service.php')] as $invalid) {
            try { QueryBudgetExtension::inspectFiles($context->analysis, $invalid); }
            catch (InvalidArgumentException) { continue; }
            throw new RuntimeException('Invalid batch inspection selector was accepted.');
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
