<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

use Mago\Sdk\Analyzer\ProjectAnalysis;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** An ephemeral index of exact Mago source snapshots for one analysis generation. */
final class Program
{
    private const MAX_PHP_FILES = 25_000;
    private const MAX_PHP_BYTES = 536_870_912;

    /** @var array<string, array{node: Node\Stmt\ClassMethod, class: string, file: string}> */
    public array $methods = [];
    /** @var array<string, array<string, string>> */
    public array $properties = [];
    /** @var array<string, string> */
    public array $parents = [];
    /** @var array<string, string> */
    public array $parseFailures = [];

    /** @param array<string, string> $bindings */
    public function __construct(public readonly ProjectAnalysis $analysis, private readonly array $bindings)
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $phpFiles = 0;
        $phpBytes = 0;
        foreach ($analysis->files as $file) {
            if (!str_ends_with($file->file, '.php')) continue;
            ++$phpFiles;
            $phpBytes += $file->size;
            if ($phpFiles > self::MAX_PHP_FILES) throw new \RuntimeException('PHP source count exceeds query-budget limit of 25000.');
            if ($phpBytes > self::MAX_PHP_BYTES) throw new \RuntimeException('PHP source bytes exceed query-budget limit of 512 MiB.');
        }
        foreach ($analysis->files as $file) {
            if (!str_ends_with($file->file, '.php')) continue;
            if ($file->size > 1_048_576) {
                $this->parseFailures[$file->file] = 'source file exceeds one MiB';
                continue;
            }
            try {
                $source = $file->getSourceFile();
                $statements = $parser->parse($source->contents);
                if ($statements === null) continue;
                $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
            } catch (\Throwable $error) {
                $this->parseFailures[$file->file] = $error::class;
                continue;
            }
            foreach ($finder->findInstanceOf($statements, Node\Stmt\Class_::class) as $class) {
                if ($class->name === null) continue;
                $className = $class->namespacedName?->toString() ?? $class->name->toString();
                if ($class->extends !== null) $this->parents[$className] = self::name($class->extends);
                $this->properties[$className] = [];
                foreach ($class->getProperties() as $property) {
                    $type = $property->type instanceof Node\Name ? self::name($property->type) : null;
                    if ($type !== null) foreach ($property->props as $prop) $this->properties[$className][$prop->name->toString()] = $this->bindings[$type] ?? $type;
                }
                foreach ($class->getMethods() as $method) {
                    $key = self::key($className, $method->name->toString());
                    $this->methods[$key] = ['node' => $method, 'class' => $className, 'file' => $file->file];
                    if (strtolower($method->name->toString()) === '__construct') {
                        foreach ($method->params as $parameter) {
                            if ($parameter->flags === 0 || !$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || !$parameter->type instanceof Node\Name) continue;
                            $type = self::name($parameter->type);
                            $target = null;
                            foreach ($parameter->attrGroups as $group) foreach ($group->attrs as $attribute) {
                                if (str_ends_with(self::name($attribute->name), '\\Target') && ($attribute->args[0]->value ?? null) instanceof Node\Scalar\String_) {
                                    $target = $attribute->args[0]->value->value;
                                }
                            }
                            $lookup = $target === null ? $type : $type . ' $' . $target;
                            $this->properties[$className][$parameter->var->name] = $this->bindings[$lookup] ?? $type;
                        }
                    }
                }
            }
        }
    }

    public function method(string $class, string $method): ?array
    {
        for ($depth = 0; $depth < 16; ++$depth) {
            $found = $this->methods[self::key($class, $method)] ?? null;
            if ($found !== null) return $found;
            $class = $this->parents[$class] ?? '';
            if ($class === '') break;
        }
        return null;
    }

    public static function key(string $class, string $method): string
    {
        return strtolower(ltrim($class, '\\') . '::' . $method);
    }

    public static function name(Node\Name $node): string
    {
        return ltrim(($node->getAttribute('resolvedName') ?? $node)->toString(), '\\');
    }
}
