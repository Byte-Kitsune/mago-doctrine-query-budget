<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget;

use ByteKitsune\MagoDoctrineQueryBudget\Analyzer\QueryBudgetPlugin;
use Mago\Sdk\Extension;

final class QueryBudgetExtension
{
    public const VERSION = '1.2.0-RC1';
    /** Name-resolved hook candidates from the exact snapshot; declarations do not prove activation. */
    public static function inspectFrameworkHooks(\Mago\Sdk\Analyzer\ProjectAnalysis $analysis): array
    {
        if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once __DIR__ . '/NativeEngine.php';
        if (!class_exists(\ByteKitsune\MagoNative\Parser::class, false)) require_once __DIR__ . '/NativeParser.php';
        if (!class_exists(\ByteKitsune\MagoNative\FrameworkHooks::class, false)) require_once __DIR__ . '/FrameworkHooks.php';
        $sources = [];
        foreach ($analysis->files as $file) if (str_ends_with($file->file, '.php')) $sources[$file->file] = $file->getSourceFile()->contents;
        return \ByteKitsune\MagoNative\FrameworkHooks::discover($sources);
    }

    /**
     * Inspect every modeled method in one exact source file, regardless of its
     * name, visibility or configured warning thresholds. The snapshot must retain
     * full project context; passing only the opened file loses transitive calls.
     *
     * @param array<string, string> $classBindings
     * @param array<string, array<int, ?string>> $constructorBindings
     * @return array{schemaVersion: string, status: string, message?: string, methods: list<array<string, mixed>>}
     */
    public static function inspectFile(
        \Mago\Sdk\Analyzer\ProjectAnalysis $analysis,
        string $file,
        array $classBindings = [],
        array $constructorBindings = [],
        ?string $method = null,
    ): array {
        return \ByteKitsune\MagoDoctrineQueryBudget\Analyzer\Inspection::file($analysis, $file, $classBindings, $constructorBindings, $method);
    }

    /** Read literal query thresholds from PHP configuration without executing it.
     * @return array{schemaVersion: string, status: string, warning?: int, error?: int, message?: string}
     */
    public static function inspectThresholds(string $source): array
    {
        return \ByteKitsune\MagoDoctrineQueryBudget\Analyzer\ThresholdInspection::source($source);
    }

    /** Inspect multiple exact source files with one shared project model.
     * @param list<string> $files
     * @param array<string, string> $classBindings
     * @param array<string, array<int, ?string>> $constructorBindings
     * @return array<string, array<string, mixed>> Reports indexed by requested source path.
     */
    public static function inspectFiles(
        \Mago\Sdk\Analyzer\ProjectAnalysis $analysis,
        array $files,
        array $classBindings = [],
        array $constructorBindings = [],
    ): array {
        return \ByteKitsune\MagoDoctrineQueryBudget\Analyzer\Inspection::files($analysis, $files, $classBindings, $constructorBindings);
    }

    /** Inspect a project index of up to 50,000 exact source paths with one model.
     * The complete snapshot supplies transitive context, including unselected files.
     * @param list<string> $files
     * @param array<string, string> $classBindings
     * @param array<string, array<int, ?string>> $constructorBindings
     * @return array<string, array<string, mixed>> Reports indexed by requested source path.
     */
    public static function inspectSnapshot(
        \Mago\Sdk\Analyzer\ProjectAnalysis $analysis,
        array $files,
        array $classBindings = [],
        array $constructorBindings = [],
    ): array {
        return \ByteKitsune\MagoDoctrineQueryBudget\Analyzer\Inspection::snapshot($analysis, $files, $classBindings, $constructorBindings);
    }

    /**
     * @param array<string, string> $classBindings Proven service type/named-target to implementation class.
     * @param array<string, array<int, ?string>> $constructorBindings Compiled service references by owner class and constructor position.
     * @param list<string> $entrypointSuffixes
     * @param list<string> $inspectEntrypoints Exact class names, optionally followed by ::method.
     * @param bool $discoverFrameworkHooks Discover declaration candidates; budgets cover one invocation, not activation.
     * @param list<string> $hookEntrypoints Exact operator/compiled-container Class::method registrations.
     */
    public static function create(
        array $classBindings = [],
        array $entrypointSuffixes = ['Controller.php', 'Command.php'],
        int $warningThreshold = 10,
        int $errorThreshold = 25,
        array $inspectEntrypoints = [],
        int $incompleteIssueLimit = 25,
        bool $assumeGlobalScalarBuiltins = false,
        array $constructorBindings = [],
        bool $discoverFrameworkHooks = true,
        array $hookEntrypoints = [],
    ): Extension {
        if ($warningThreshold < 1 || $errorThreshold < $warningThreshold || $entrypointSuffixes === [] || $incompleteIssueLimit < 0 || $incompleteIssueLimit > 1000) {
            throw new \InvalidArgumentException('Invalid query budget thresholds or selectors.');
        }
        foreach ($entrypointSuffixes as $suffix) {
            if (!is_string($suffix) || $suffix === '' || str_contains($suffix, '/') || !str_ends_with($suffix, '.php')) {
                throw new \InvalidArgumentException('Entrypoint selectors must be PHP filename suffixes.');
            }
        }
        foreach ($constructorBindings as $owner => $positions) {
            if (!is_string($owner) || $owner === '' || !is_array($positions)) throw new \InvalidArgumentException('Invalid constructor binding owner.');
            foreach ($positions as $position => $class) {
                if (!is_int($position) || $position < 0 || $position > 127 || $class !== null && (!is_string($class) || $class === '')) {
                    throw new \InvalidArgumentException('Invalid constructor binding position or class.');
                }
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
        if (count($hookEntrypoints) > 10000) throw new \InvalidArgumentException('At most 10000 hook entrypoints may be supplied.');
        $hooks = [];
        foreach ($hookEntrypoints as $target) {
            if (!is_string($target) || strlen($target) > 512) throw new \InvalidArgumentException('Invalid hook entrypoint.');
            $target = ltrim($target, '\\'); $parts = explode('::', $target);
            if (count($parts) !== 2 || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $parts[0])
                || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $parts[1])) {
                throw new \InvalidArgumentException('Hook entrypoints must be exact Class::method targets.');
            }
            $hooks[strtolower($target)] = $target;
        }
        return new Extension(
            identifier: 'byte-kitsune/doctrine-query-budget',
            name: 'Doctrine query budget',
            version: self::VERSION,
            analyzerPlugins: [new QueryBudgetPlugin($classBindings, $entrypointSuffixes, $warningThreshold, $errorThreshold, array_values($normalized), $incompleteIssueLimit, $assumeGlobalScalarBuiltins, $constructorBindings, $discoverFrameworkHooks, array_values($hooks))],
        );
    }
}
