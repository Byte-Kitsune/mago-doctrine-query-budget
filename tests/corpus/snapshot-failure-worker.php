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

require getenv('MAGO_VENDOR_AUTOLOAD');

final class SnapshotFailureHook implements AfterAnalysisHook
{
    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $files = ['src/Oversized.php', 'src/Healthy.php', 'src/LargeSource.php', 'src/Missing.php'];
        $reports = QueryBudgetExtension::inspectSnapshot($context->analysis, $files);
        if (array_keys($reports) !== $files || $reports['src/Oversized.php']['status'] !== 'failed'
            || $reports['src/Oversized.php']['methods'] !== []
            || !str_contains($reports['src/Oversized.php']['message'], '512 method')
            || $reports['src/Healthy.php']['status'] !== 'complete'
            || $reports['src/Healthy.php']['methods'][0]['upperBound'] !== 0
            || $reports['src/LargeSource.php']['status'] !== 'failed'
            || !str_contains($reports['src/LargeSource.php']['message'], 'one MiB')
            || $reports['src/Missing.php']['status'] !== 'unsupported') {
            throw new RuntimeException('Snapshot failure isolation changed semantics.');
        }
        foreach (['inspectFile', 'inspectFiles'] as $method) {
            try { QueryBudgetExtension::$method($context->analysis, $method === 'inspectFile' ? $files[0] : $files); }
            catch (RuntimeException $error) {
                if (str_contains($error->getMessage(), '512 method')) continue;
                throw $error;
            }
            throw new RuntimeException('Legacy inspection no longer rejects the method limit.');
        }
        file_put_contents(getenv('MAGO_INSPECTION_FAILURE_OUTPUT'), "Snapshot file-failure isolation and legacy method limit passed\n");
    }
}

final class SnapshotFailurePlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('byte-kitsune/snapshot-failure', 'Snapshot failure', 'Verifies file-local failures.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook(new SnapshotFailureHook());
    }
}

(new Worker(new Extension('byte-kitsune/snapshot-failure', 'Snapshot failure', '1', analyzerPlugins: [new SnapshotFailurePlugin()])))->run();
