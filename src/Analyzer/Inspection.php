<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

use Mago\Sdk\Analyzer\ProjectAnalysis;

/** Read-only file inspection over the complete Mago analysis snapshot. */
final class Inspection
{
    /**
     * @param array<string, string> $classBindings
     * @param array<string, array<int, ?string>> $constructorBindings
     * @return array{schemaVersion: string, status: string, message?: string, methods: list<array<string, mixed>>}
     */
    public static function file(
        ProjectAnalysis $analysis,
        string $file,
        array $classBindings = [],
        array $constructorBindings = [],
        ?string $method = null,
    ): array {
        if ($file === '' || strlen($file) > 4096 || str_contains($file, "\0")) {
            throw new \InvalidArgumentException('Inspection requires an exact Mago source path.');
        }
        if ($method !== null) {
            $method = ltrim($method, '\\');
            if (strlen($method) > 1024 || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*::[A-Za-z_][A-Za-z0-9_]*\z/', $method)) {
                throw new \InvalidArgumentException('Method inspection requires an exact Class::method symbol.');
            }
        }
        $target = str_replace('\\', '/', $file);
        $matches = static fn(string $path): bool => str_replace('\\', '/', $path) === $target;
        $seen = false;
        foreach ($analysis->files as $source) {
            if ($matches($source->file)) $seen = true;
        }
        if (!$seen) return self::unavailable('unsupported', 'The file is absent from the configured Mago source snapshot.');
        $program = new Program($analysis, $classBindings, $constructorBindings);
        return self::report($program, $file, $method);
    }

    /** Inspect a complete batch while constructing the project model exactly once.
     * @param list<string> $files
     * @param array<string, string> $classBindings
     * @param array<string, array<int, ?string>> $constructorBindings
     * @return array<string, array<string, mixed>>
     */
    public static function files(ProjectAnalysis $analysis, array $files, array $classBindings = [], array $constructorBindings = []): array
    {
        if (count($files) > 2000) throw new \InvalidArgumentException('At most 2000 source files may be inspected.');
        $selectors = [];
        foreach ($files as $file) {
            if (!is_string($file) || $file === '' || strlen($file) > 4096 || str_contains($file, "\0"))
                throw new \InvalidArgumentException('Inspection requires exact Mago source paths.');
            $normalized = str_replace('\\', '/', $file);
            if (isset($selectors[$normalized])) throw new \InvalidArgumentException('Duplicate inspection source path.');
            $selectors[$normalized] = $file;
        }
        if ($files === []) return [];
        $seen = [];
        foreach ($analysis->files as $source) $seen[str_replace('\\', '/', $source->file)] = true;
        $program = new Program($analysis, $classBindings, $constructorBindings);
        $models = [];
        foreach ($program->methods as $model) $models[str_replace('\\', '/', $model['file'])][] = $model;
        $reports = [];
        foreach ($selectors as $normalized => $file) {
            $reports[$file] = isset($seen[$normalized])
                ? self::report($program, $file, null, $models[$normalized] ?? [])
                : self::unavailable('unsupported', 'The file is absent from the configured Mago source snapshot.');
        }
        return $reports;
    }

    /** @param array<string, mixed>|null $models
     * @return array<string, mixed>
     */
    private static function report(Program $program, string $file, ?string $method, ?array $models = null): array
    {
        $target = str_replace('\\', '/', $file);
        $matches = static fn(string $path): bool => str_replace('\\', '/', $path) === $target;
        foreach ($program->parseFailures as $path => $reason) {
            if ($matches($path)) return self::unavailable('failed', 'The PHP file could not be parsed: ' . $reason);
        }
        $methods = [];
        $incomplete = false;
        foreach ($models ?? $program->methods as $model) {
            if (!$matches($model['file'])) continue;
            $name = $model['node']->name->toString();
            $symbol = $model['class'] . '::' . $name;
            if ($method !== null && strcasecmp($method, $symbol) !== 0) continue;
            if (count($methods) >= 512) throw new \RuntimeException('File exceeds the 512 method inspection limit.');
            $estimate = $model['node']->stmts === null
                ? Estimate::unknown('Method implementation is unavailable.')
                : (new Evaluator($program))->method($model['class'], $name);
            $unknown = array_slice($estimate->unknown, 0, 32);
            $upper = $estimate->upper;
            // A JSON consumer must not silently round an integer and claim a precise bound.
            if ($estimate->lower > 9007199254740991 || $upper !== null && $upper > 9007199254740991) {
                $upper = null;
                $unknown[] = 'Query estimate exceeds the representable integer range.';
            }
            if ($upper === null || $unknown !== [] || $estimate->cycles !== []) $incomplete = true;
            $methods[] = [
                'symbol' => $symbol,
                'path' => $file,
                'line' => max(1, $model['node']->name->getStartLine()),
                'lowerBound' => min(9007199254740991, $estimate->lower),
                'upperBound' => $upper,
                'unknown' => array_slice($unknown, 0, 32),
                'cycles' => array_slice($estimate->cycles, 0, 32),
            ];
        }
        if ($method !== null && $methods === []) return self::unavailable('unsupported', 'The requested method is absent from this source file.');
        usort($methods, static fn(array $a, array $b): int => [$a['line'], $a['symbol']] <=> [$b['line'], $b['symbol']]);
        return ['schemaVersion' => '1', 'status' => $incomplete ? 'incomplete' : 'complete', 'methods' => $methods];
    }

    /** @return array{schemaVersion: string, status: string, message: string, methods: list<array<string, mixed>>} */
    private static function unavailable(string $status, string $message): array
    {
        return ['schemaVersion' => '1', 'status' => $status, 'message' => $message, 'methods' => []];
    }
}
