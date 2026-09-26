<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget;

use ByteKitsune\MagoDoctrineQueryBudget\Analyzer\QueryBudgetPlugin;
use Mago\Sdk\Extension;

final class QueryBudgetExtension
{
    /**
     * @param array<string, string> $classBindings Proven service type/named-target to implementation class.
     * @param list<string> $entrypointSuffixes
     */
    public static function create(
        array $classBindings = [],
        array $entrypointSuffixes = ['Controller.php', 'Command.php'],
        int $warningThreshold = 10,
        int $errorThreshold = 25,
    ): Extension {
        if ($warningThreshold < 1 || $errorThreshold < $warningThreshold || $entrypointSuffixes === []) {
            throw new \InvalidArgumentException('Invalid query budget thresholds or selectors.');
        }
        foreach ($entrypointSuffixes as $suffix) {
            if (!is_string($suffix) || $suffix === '' || str_contains($suffix, '/') || !str_ends_with($suffix, '.php')) {
                throw new \InvalidArgumentException('Entrypoint selectors must be PHP filename suffixes.');
            }
        }
        return new Extension(
            identifier: 'byte-kitsune/doctrine-query-budget',
            name: 'Doctrine query budget',
            version: '0.1.0-beta.2',
            analyzerPlugins: [new QueryBudgetPlugin($classBindings, $entrypointSuffixes, $warningThreshold, $errorThreshold)],
        );
    }
}
