<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;

final class QueryBudgetHook implements AfterAnalysisHook
{
    /** @param array<string, string> $bindings @param list<string> $suffixes @param list<string> $inspectEntrypoints */
    public function __construct(
        private readonly array $bindings,
        private readonly array $suffixes,
        private readonly int $warning,
        private readonly int $error,
        private readonly array $inspectEntrypoints,
        private readonly int $incompleteIssueLimit,
        private readonly bool $assumeGlobalScalarBuiltins,
        private readonly array $constructorBindings,
    ) {}

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $program = new Program($context->analysis, $this->bindings, $this->constructorBindings);
        $requested = [];
        foreach ($this->inspectEntrypoints as $selection) $requested[strtolower($selection)] = false;
        $entrypointsAnalyzed = 0;
        $incompleteEntrypoints = 0;
        $reportedIncomplete = 0;
        $methods = $program->methods;
        ksort($methods, SORT_STRING);
        foreach ($methods as $model) {
            $context->cancellation->throwIfCancelled();
            $suffix = $this->entrypointSuffix($model['file'], $model['class']);
            if ($suffix === null) continue;
            $name = $model['node']->name->toString();
            if ($name === '__construct' || str_starts_with($name, '__') && $name !== '__invoke') continue;
            if ($suffix === 'Command.php') {
                if ($name === 'execute' && !$model['node']->isPublic() && !$model['node']->isProtected()) continue;
                if ($name === '__invoke' && !$model['node']->isPublic()) continue;
                if (!in_array($name, ['execute', '__invoke'], true)) continue;
            } elseif (!$model['node']->isPublic()) continue;
            $classKey = strtolower($model['class']);
            $methodKey = strtolower($model['class'] . '::' . $name);
            if ($requested !== [] && !array_key_exists($classKey, $requested) && !array_key_exists($methodKey, $requested)) continue;
            if (array_key_exists($classKey, $requested)) $requested[$classKey] = true;
            if (array_key_exists($methodKey, $requested)) $requested[$methodKey] = true;
            ++$entrypointsAnalyzed;
            $estimate = (new Evaluator($program, $this->assumeGlobalScalarBuiltins))->method($model['class'], $name);
            $location = new SourceLocation($model['file'], new Span($model['node']->name->getStartFilePos(), $model['node']->name->getEndFilePos() + 1));
            $evidence = json_encode([
                'schema_version' => '1',
                'entrypoint' => $model['class'] . '::' . $name,
                'lower_bound' => $estimate->lower,
                'upper_bound' => $estimate->upper,
                'unknown' => array_slice($estimate->unknown, 0, 8),
                'cycles' => array_slice($estimate->cycles, 0, 8),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ($requested !== []) {
                $context->report(Level::Note, 'query-budget-inspection', Issue::at('Query budget for ' . $model['class'] . '::' . $name . ': ' . $estimate->lower . '..' . ($estimate->upper ?? 'unknown') . ' database statements.', $location)->withNote('query-budget-evidence: ' . $evidence));
            }
            if ($estimate->cycles !== []) {
                $context->report(Level::Error, 'recursive-call', Issue::at('Unbounded recursive call path from ' . $model['class'] . '::' . $name, $location)->withNote('query-budget-evidence: ' . $evidence));
            }
            if ($estimate->lower > $this->error) {
                $context->report(Level::Error, 'query-budget-exceeded', Issue::at('At least ' . $estimate->lower . ' database statements exceed the error threshold ' . $this->error, $location)->withNote('query-budget-evidence: ' . $evidence));
            } elseif ($estimate->upper !== null && $estimate->upper > $this->warning || $estimate->lower > $this->warning) {
                $context->report(Level::Warning, 'query-budget-exceeded', Issue::at('Database statement estimate exceeds the warning threshold ' . $this->warning, $location)->withNote('query-budget-evidence: ' . $evidence));
            }
            if ($estimate->upper === null || $estimate->unknown !== []) {
                ++$incompleteEntrypoints;
                if ($reportedIncomplete < $this->incompleteIssueLimit) {
                    ++$reportedIncomplete;
                    $context->report(Level::Warning, 'query-budget-incomplete', Issue::at('Query budget could not be bounded for ' . $model['class'] . '::' . $name, $location)->withNote('query-budget-evidence: ' . $evidence));
                }
            }
        }
        $sourceFiles = 0;
        $firstSource = null;
        $firstFile = null;
        foreach ($context->analysis->files as $file) {
            if (!str_ends_with($file->file, '.php')) continue;
            $sourceFiles++;
            if ($firstFile === null || strcmp($file->file, $firstFile) < 0) {
                $firstFile = $file->file;
                $firstSource = $file->getSourceFile();
            }
        }
        if ($firstSource !== null) {
            $summaryLocation = new SourceLocation($firstSource->path, new Span(0, $firstSource->contents === '' ? 0 : 1));
            $omittedIncomplete = $incompleteEntrypoints - $reportedIncomplete;
            if ($omittedIncomplete > 0) {
                $summary = [
                    'schema_version' => '1',
                    'incomplete_entrypoints' => $incompleteEntrypoints,
                    'reported_entrypoints' => $reportedIncomplete,
                    'omitted_entrypoints' => $omittedIncomplete,
                ];
                $context->report(Level::Warning, 'query-budget-incomplete-summary', Issue::at($omittedIncomplete . ' incomplete query budgets omitted; ' . $reportedIncomplete . ' detailed warning(s) shown.', $summaryLocation)->withNote('query-budget-summary: ' . json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
            }
            foreach ($this->inspectEntrypoints as $selection) {
                if ($requested[strtolower($selection)] ?? false) continue;
                $context->report(Level::Error, 'inspection-target-not-found', Issue::at('No public controller or command entrypoint matches ' . $selection . '.', $summaryLocation));
            }
            $attestation = [
                'schema_version' => '1',
                'extension' => 'byte-kitsune/doctrine-query-budget',
                'version' => QueryBudgetExtension::VERSION,
                'capability' => 'query_budget',
                'complete' => true,
                'source_files' => $sourceFiles,
                'entrypoints_analyzed' => $entrypointsAnalyzed,
                'incomplete_entrypoints' => $incompleteEntrypoints,
                'reported_incomplete_entrypoints' => $reportedIncomplete,
                'omitted_incomplete_entrypoints' => $omittedIncomplete,
                'requested_inspections' => count($requested),
                'matched_inspections' => count(array_filter($requested)),
            ];
            $note = 'extension-attestation: ' . json_encode($attestation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $context->report(Level::Note, 'analysis-attestation', Issue::at('Doctrine query budget analysis completed.', $summaryLocation)->withNote($note));
        }
    }

    private function entrypointSuffix(string $file, string $class): ?string
    {
        foreach ($this->suffixes as $suffix) {
            if (!str_ends_with($file, $suffix)) continue;
            $classSuffix = substr($suffix, 0, -4);
            if (str_ends_with($class, $classSuffix)) return $suffix;
        }
        return null;
    }
}
