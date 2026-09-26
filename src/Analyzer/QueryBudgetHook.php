<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;

final class QueryBudgetHook implements AfterAnalysisHook
{
    /** @param array<string, string> $bindings @param list<string> $suffixes */
    public function __construct(
        private readonly array $bindings,
        private readonly array $suffixes,
        private readonly int $warning,
        private readonly int $error,
    ) {}

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $program = new Program($context->analysis, $this->bindings);
        foreach ($program->methods as $model) {
            $context->cancellation->throwIfCancelled();
            $suffix = $this->entrypointSuffix($model['file'], $model['class']);
            if ($suffix === null || !$model['node']->isPublic()) continue;
            $name = $model['node']->name->toString();
            if ($name === '__construct' || str_starts_with($name, '__') && $name !== '__invoke') continue;
            if ($suffix === 'Command.php' && !in_array($name, ['execute', '__invoke'], true)) continue;
            $estimate = (new Evaluator($program))->method($model['class'], $name);
            $location = new SourceLocation($model['file'], new Span($model['node']->name->getStartFilePos(), $model['node']->name->getEndFilePos() + 1));
            $evidence = json_encode([
                'schema_version' => '1',
                'entrypoint' => $model['class'] . '::' . $name,
                'lower_bound' => $estimate->lower,
                'upper_bound' => $estimate->upper,
                'unknown' => array_slice($estimate->unknown, 0, 8),
                'cycles' => array_slice($estimate->cycles, 0, 8),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ($estimate->cycles !== []) {
                $context->report(Level::Error, 'recursive-call', Issue::at('Unbounded recursive call path from ' . $model['class'] . '::' . $name, $location)->withNote('query-budget-evidence: ' . $evidence));
            }
            if ($estimate->lower > $this->error) {
                $context->report(Level::Error, 'query-budget-exceeded', Issue::at('At least ' . $estimate->lower . ' database statements exceed the error threshold ' . $this->error, $location)->withNote('query-budget-evidence: ' . $evidence));
            } elseif ($estimate->upper !== null && $estimate->upper > $this->warning || $estimate->lower > $this->warning) {
                $context->report(Level::Warning, 'query-budget-exceeded', Issue::at('Database statement estimate exceeds the warning threshold ' . $this->warning, $location)->withNote('query-budget-evidence: ' . $evidence));
            }
            if ($estimate->upper === null || $estimate->unknown !== []) {
                $context->report(Level::Warning, 'query-budget-incomplete', Issue::at('Query budget could not be bounded for ' . $model['class'] . '::' . $name, $location)->withNote('query-budget-evidence: ' . $evidence));
            }
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
