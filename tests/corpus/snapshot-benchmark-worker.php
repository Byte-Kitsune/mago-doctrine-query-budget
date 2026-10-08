<?php

declare(strict_types=1);

use ByteKitsune\MagoDoctrineQueryBudget\Analyzer\Program;
use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

require getenv('MAGO_VENDOR_AUTOLOAD');

final class SnapshotBenchmarkHook implements AfterAnalysisHook
{
    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $files = array_map(static fn($file): string => $file->file, $context->analysis->files);
        sort($files);
        // Fetch source snapshots before either timed inspection so lazy host I/O
        // cannot favor the API measured second.
        $started = hrtime(true);
        $program = new Program($context->analysis, []);
        $prepareSeconds = (hrtime(true) - $started) / 1e9;
        unset($program);
        gc_collect_cycles();
        $started = hrtime(true);
        $batchHashes = [];
        foreach (array_chunk($files, 2000) as $batch) {
            foreach (QueryBudgetExtension::inspectFiles($context->analysis, $batch) as $path => $report) {
                $batchHashes[$path] = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR));
            }
        }
        $batchSeconds = (hrtime(true) - $started) / 1e9;
        gc_collect_cycles();
        $started = hrtime(true);
        $reports = QueryBudgetExtension::inspectSnapshot($context->analysis, $files);
        $snapshotSeconds = (hrtime(true) - $started) / 1e9;
        if (array_keys($reports) !== $files) throw new RuntimeException('Missing snapshot reports.');
        foreach ($reports as $path => $report) {
            if ($batchHashes[$path] !== hash('sha256', json_encode($report, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Snapshot changed report: ' . $path);
            }
            if ($report['status'] !== 'complete' || count($report['methods']) !== 1 || $report['methods'][0]['upperBound'] !== 0) {
                throw new RuntimeException('Unexpected scale fixture estimate: ' . $path);
            }
        }
        file_put_contents(getenv('MAGO_INSPECTION_BENCHMARK_OUTPUT'), json_encode([
            'files' => count($files),
            'batches' => count(array_chunk($files, 2000)),
            'coldModelSeconds' => $prepareSeconds,
            'warmBatchSeconds' => $batchSeconds,
            'warmSnapshotSeconds' => $snapshotSeconds,
            'speedup' => $batchSeconds / max($snapshotSeconds, 0.000001),
            'peakMemoryBytes' => memory_get_peak_usage(true),
            'identicalReports' => true,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }
}

final class SnapshotBenchmarkPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('byte-kitsune/snapshot-benchmark', 'Snapshot benchmark', 'Verifies complete index equivalence and model reuse timings.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook(new SnapshotBenchmarkHook());
    }
}

(new Worker(new Extension('byte-kitsune/snapshot-benchmark', 'Snapshot benchmark', '1', analyzerPlugins: [new SnapshotBenchmarkPlugin()])))->run();
