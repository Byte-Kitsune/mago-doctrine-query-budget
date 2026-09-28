<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

final class QueryBudgetPlugin implements Plugin
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

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('byte-kitsune/doctrine-query-budget', 'Doctrine query budget', 'Bounds statements per entrypoint call.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook(new QueryBudgetHook($this->bindings, $this->suffixes, $this->warning, $this->error, $this->inspectEntrypoints, $this->incompleteIssueLimit, $this->assumeGlobalScalarBuiltins, $this->constructorBindings));
    }
}
