<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget;

use ByteKitsune\MagoDoctrineQueryBudget\Analyzer\QueryBudgetPlugin;
use Mago\Sdk\Extension;

final class QueryBudgetExtension
{
    public const VERSION = '0.1.0-beta.9';
    /**
     * @param array<string, string> $classBindings Proven service type/named-target to implementation class.
     * @param list<string> $entrypointSuffixes
     * @param list<string> $inspectEntrypoints Exact class names, optionally followed by ::method.
     */
    public static function create(
        array $classBindings = [],
        array $entrypointSuffixes = ['Controller.php', 'Command.php'],
        int $warningThreshold = 10,
        int $errorThreshold = 25,
        array $inspectEntrypoints = [],
        int $incompleteIssueLimit = 25,
        bool $assumeGlobalScalarBuiltins = false,
    ): Extension {
        if ($warningThreshold < 1 || $errorThreshold < $warningThreshold || $entrypointSuffixes === [] || $incompleteIssueLimit < 0 || $incompleteIssueLimit > 1000) {
            throw new \InvalidArgumentException('Invalid query budget thresholds or selectors.');
        }
        foreach ($entrypointSuffixes as $suffix) {
            if (!is_string($suffix) || $suffix === '' || str_contains($suffix, '/') || !str_ends_with($suffix, '.php')) {
                throw new \InvalidArgumentException('Entrypoint selectors must be PHP filename suffixes.');
            }
        }
        if (count($inspectEntrypoints) > 32) throw new \InvalidArgumentException('At most 32 entrypoints may be inspected.');
        $normalized = [];
        foreach ($inspectEntrypoints as $selection) {
            if (!is_string($selection) || strlen($selection) > 512) throw new \InvalidArgumentException('Invalid inspection entrypoint.');
            $selection = ltrim($selection, '\\');
            $parts = explode('::', $selection);
            if (count($parts) > 2 || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $parts[0])
                || isset($parts[1]) && !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $parts[1])) {
                throw new \InvalidArgumentException('Inspection entrypoints must be exact PHP class names, optionally followed by ::method.');
            }
            $key = strtolower($selection);
            if (isset($normalized[$key])) throw new \InvalidArgumentException('Duplicate inspection entrypoint.');
            $normalized[$key] = $selection;
        }
        return new Extension(
            identifier: 'byte-kitsune/doctrine-query-budget',
            name: 'Doctrine query budget',
            version: self::VERSION,
            analyzerPlugins: [new QueryBudgetPlugin($classBindings, $entrypointSuffixes, $warningThreshold, $errorThreshold, array_values($normalized), $incompleteIssueLimit, $assumeGlobalScalarBuiltins)],
        );
    }
}
