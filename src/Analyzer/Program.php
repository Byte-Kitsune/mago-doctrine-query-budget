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
    /** @var array<string, array{node: Node\Stmt\ClassMethod, class: string, file: string}> */
    public array $traitMethods = [];
    /** @var array<string, true> */
    public array $traitDefinitions = [];
    /** @var array<string, list<string>> */
    public array $traitUses = [];
    /** @var array<string, true> */
    public array $adaptedTraitUses = [];
    /** @var array<string, array{node: Node\Stmt\Function_, name: string, file: string}> */
    public array $functions = [];
    /** @var array<string, true> */
    public array $ambiguousFunctions = [];
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
            $this->indexFunctions($statements, $file->file);
            foreach ($finder->findInstanceOf($statements, Node\Stmt\Trait_::class) as $trait) {
                if ($trait->name === null) continue;
                $traitName = $trait->namespacedName?->toString() ?? $trait->name->toString();
                $this->traitDefinitions[strtolower($traitName)] = true;
                $this->indexTraitUses($traitName, $trait->stmts);
                foreach ($trait->getMethods() as $method) {
                    $this->traitMethods[self::key($traitName, $method->name->toString())] = [
                        'node' => $method, 'class' => $traitName, 'file' => $file->file,
                    ];
                }
            }
            foreach ($finder->findInstanceOf($statements, Node\Stmt\Class_::class) as $class) {
                if ($class->name === null) continue;
                $className = $class->namespacedName?->toString() ?? $class->name->toString();
                if ($class->extends !== null) $this->parents[$className] = self::name($class->extends);
                $this->indexTraitUses($className, $class->stmts);
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

    /** @param list<Node\Stmt> $statements */
    private function indexTraitUses(string $owner, array $statements): void
    {
        foreach ($statements as $statement) {
            if (!$statement instanceof Node\Stmt\TraitUse) continue;
            if ($statement->adaptations !== []) {
                // Aliases and conflict selection change method identity. Keep
                // this class unknown until those adaptations are modeled.
                $this->adaptedTraitUses[strtolower($owner)] = true;
                continue;
            }
            foreach ($statement->traits as $trait) $this->traitUses[strtolower($owner)][] = strtolower(self::name($trait));
        }
    }

    /** @param list<Node\Stmt> $statements */
    private function indexFunctions(array $statements, string $file): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                $this->indexFunctions($statement->stmts, $file);
            } elseif ($statement instanceof Node\Stmt\Function_) {
                $name = $statement->namespacedName?->toString() ?? $statement->name->toString();
                $key = strtolower($name);
                if (isset($this->functions[$key]) || isset($this->ambiguousFunctions[$key])) {
                    unset($this->functions[$key]);
                    $this->ambiguousFunctions[$key] = true;
                } else {
                    $this->functions[$key] = ['node' => $statement, 'name' => $name, 'file' => $file];
                }
            }
        }
    }

    public function functionModel(string $name): ?array
    {
        return $this->functions[strtolower(ltrim($name, '\\'))] ?? null;
    }

    public function method(string $class, string $method): ?array
    {
        for ($depth = 0; $depth < 16; ++$depth) {
            $found = $this->methods[self::key($class, $method)] ?? null;
            if ($found !== null) return $found;
            if (!$this->traitGraphComplete($class, [])) return null;
            $found = $this->traitMethod($class, $method, $class, []);
            if ($found !== null) return $found;
            $class = $this->parents[$class] ?? '';
            if ($class === '') break;
        }
        return null;
    }

    /** @param list<string> $visited */
    private function traitGraphComplete(string $owner, array $visited): bool
    {
        $owner = strtolower($owner);
        if (count($visited) >= 16 || isset($this->adaptedTraitUses[$owner])) return false;
        foreach ($this->traitUses[$owner] ?? [] as $trait) {
            if (!isset($this->traitDefinitions[$trait]) || in_array($trait, $visited, true)
                || !$this->traitGraphComplete($trait, [...$visited, $trait])) return false;
        }
        return true;
    }

    /** @param list<string> $visited */
    private function traitMethod(string $owner, string $method, string $consumer, array $visited): ?array
    {
        $owner = strtolower($owner);
        if (count($visited) >= 16 || isset($this->adaptedTraitUses[$owner])) return null;
        foreach ($this->traitUses[$owner] ?? [] as $trait) {
            if (in_array($trait, $visited, true)) return null;
            $model = $this->traitMethods[self::key($trait, $method)] ?? null;
            if ($model !== null) return ['node' => $model['node'], 'class' => $consumer, 'file' => $model['file']];
            $model = $this->traitMethod($trait, $method, $consumer, [...$visited, $trait]);
            if ($model !== null) return $model;
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
