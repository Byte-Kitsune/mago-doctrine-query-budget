<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once dirname(__DIR__) . '/NativeEngine.php';
if (!class_exists(\ByteKitsune\MagoNative\Parser::class, false)) require_once dirname(__DIR__) . '/NativeParser.php';


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
    public array $interfaces = [];
    public array $constants = [];
    /** @var array<string, string> */
    public array $parseFailures = [];
    public bool $native = false;
    public string $nativeSession = 'legacy';
    private static ?\WeakMap $nativeCache = null;

    /** @param array<string, string> $bindings
     *  @param array<string, array<int, ?string>> $constructorBindings
     */
    public function __construct(
        public readonly ProjectAnalysis $analysis,
        private readonly array $bindings,
        private readonly array $constructorBindings = [],
    )
    {
        $parser = \ByteKitsune\MagoNative\Parser::create();
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
        if (\ByteKitsune\MagoNative\Engine::enabled()) {
            self::$nativeCache ??= new \WeakMap();
            $signature = hash('sha256', json_encode([$this->bindings, $this->constructorBindings], JSON_THROW_ON_ERROR));
            $cached = self::$nativeCache[$analysis][$signature] ?? null;
            $this->native = true;
            if ($cached !== null) {
                $this->nativeSession = $cached['session'];
                $this->methods = $cached['methods'];
                return;
            }
            $this->nativeSession = \ByteKitsune\MagoNative\Engine::beginNativeBudget($this->bindings, $this->constructorBindings);
            foreach ($analysis->files as $file) {
                if (!str_ends_with($file->file, '.php')) continue;
                if ($file->size > 1_048_576) { $this->parseFailures[$file->file] = 'source file exceeds one MiB'; continue; }
                try {
                    $source = $file->getSourceFile();
                    $metadata = \ByteKitsune\MagoNative\Engine::indexBudgetSource($this->nativeSession, $file->file, $source->contents);
                } catch (\Throwable $error) { $this->parseFailures[$file->file] = $error::class; continue; }
                foreach ($metadata as $method) {
                    $identifier = new Node\Identifier($method['name'], $method['name_attributes']);
                    $attributes = array_intersect_key($method['method_attributes'], array_flip(['startFilePos', 'endFilePos', 'startLine', 'endLine']));
                    $node = new Node\Stmt\ClassMethod($identifier, ['flags' => $method['flags'], 'stmts' => $method['abstract'] ? null : []], $attributes);
                    $this->methods[$method['key']] = ['node' => $node, 'class' => $method['class'], 'file' => $method['file']];
                }
            }
            if ($this->parseFailures === []) {
                $variants = self::$nativeCache[$analysis] ?? [];
                $variants[$signature] = ['session' => $this->nativeSession, 'methods' => $this->methods];
                self::$nativeCache[$analysis] = $variants;
            }
            return;
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
            foreach ($finder->findInstanceOf($statements, Node\Stmt\Const_::class) as $declaration) foreach ($declaration->consts as $constant) {
                $this->constants[$constant->namespacedName?->toString() ?? $constant->name->toString()] = ['node' => $constant->value, 'class' => '', 'file' => $file->file];
            }
            foreach ($finder->findInstanceOf($statements, Node\Stmt\Interface_::class) as $interface) {
                $name = $interface->namespacedName?->toString() ?? $interface->name->toString();
                $this->interfaces[$name] = array_map(self::name(...), $interface->extends);
            }
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
                $this->interfaces[$className] = array_map(self::name(...), $class->implements);
                foreach ($class->getConstants() as $declaration) foreach ($declaration->consts as $constant) {
                    $this->constants[strtolower($className) . '::' . $constant->name->toString()] = ['node' => $constant->value, 'class' => $className, 'file' => $file->file];
                }
                $this->indexTraitUses($className, $class->stmts);
                $this->properties[$className] = [];
                $declaredPropertyTypes = [];
                foreach ($class->getProperties() as $property) {
                    $type = $property->type instanceof Node\Name ? self::name($property->type) : null;
                    if ($type !== null) foreach ($property->props as $prop) {
                        $name = $prop->name->toString();
                        $declaredPropertyTypes[$name] = $type;
                        $this->properties[$className][$name] = $this->bindings[$type] ?? $type;
                    }
                }
                foreach ($class->getMethods() as $method) {
                    $key = self::key($className, $method->name->toString());
                    $this->methods[$key] = ['node' => $method, 'class' => $className, 'file' => $file->file];
                    if (strtolower($method->name->toString()) === '__construct') {
                        $parameterClasses = [];
                        foreach ($method->params as $position => $parameter) {
                            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || !$parameter->type instanceof Node\Name) continue;
                            $type = self::name($parameter->type);
                            $target = null;
                            foreach ($parameter->attrGroups as $group) foreach ($group->attrs as $attribute) {
                                if (str_ends_with(self::name($attribute->name), '\\Target') && ($attribute->args[0]->value ?? null) instanceof Node\Scalar\String_) {
                                    $target = $attribute->args[0]->value->value;
                                }
                            }
                            $lookup = $target === null ? $type : $type . ' $' . $target;
                            $hasCompiledBinding = array_key_exists($position, $this->constructorBindings[$className] ?? []);
                            $class = $hasCompiledBinding
                                ? $this->constructorBindings[$className][$position] : ($this->bindings[$lookup] ?? $type);
                            $parameterClasses[$parameter->var->name] = $class;
                            if ($hasCompiledBinding) foreach ($declaredPropertyTypes as $property => $declaredType) {
                                if ($declaredType === $type) unset($this->properties[$className][$property]);
                            }
                            if ($parameter->flags === 0) continue;
                            if ($class === null) unset($this->properties[$className][$parameter->var->name]);
                            else $this->properties[$className][$parameter->var->name] = $class;
                        }
                        // Ordinary non-promoted constructor assignments are
                        // common in Symfony services. Only direct assignments
                        // from a typed parameter have a proven receiver class.
                        foreach ($method->stmts ?? [] as $statement) {
                            if (!$statement instanceof Node\Stmt\Expression || !$statement->expr instanceof Node\Expr\Assign) continue;
                            $assignment = $statement->expr;
                            if (!$assignment->var instanceof Node\Expr\PropertyFetch
                                || !$assignment->var->var instanceof Node\Expr\Variable
                                || $assignment->var->var->name !== 'this'
                                || !$assignment->var->name instanceof Node\Identifier
                                || !$assignment->expr instanceof Node\Expr\Variable
                                || !is_string($assignment->expr->name)
                                || !array_key_exists($assignment->expr->name, $parameterClasses)) continue;
                            $property = $assignment->var->name->toString();
                            $class = $parameterClasses[$assignment->expr->name];
                            if ($class === null) unset($this->properties[$className][$property]);
                            else $this->properties[$className][$property] = $class;
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

    public function parentClass(string $class): ?string
    {
        foreach ($this->parents as $owner => $parent) if (strcasecmp($owner, $class) === 0) return $parent;
        return null;
    }

    public function apiType(string $class): ?string
    {
        $known = ['Doctrine\\DBAL\\Connection', 'Doctrine\\DBAL\\Statement', 'Doctrine\\DBAL\\Result', 'Doctrine\\DBAL\\Query\\QueryBuilder', 'Doctrine\\ORM\\QueryBuilder', 'Doctrine\\ORM\\Query', 'Doctrine\\ORM\\AbstractQuery', 'Doctrine\\ORM\\NativeQuery', 'Doctrine\\ORM\\EntityRepository', 'Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository', 'Doctrine\\ORM\\EntityManagerInterface', 'Doctrine\\ORM\\EntityManager', 'Symfony\\Contracts\\Cache\\CacheInterface', 'Symfony\\Contracts\\Cache\\TagAwareCacheInterface'];
        $queue = [$class]; $seen = [];
        while ($queue !== []) {
            $next = array_pop($queue);
            if (count($seen) >= 48) return null;
            if (isset($seen[strtolower($next)])) continue;
            $seen[strtolower($next)] = true;
            foreach ($known as $api) if (strcasecmp($api, $next) === 0) return $api;
            if (($parent = $this->parentClass($next)) !== null) $queue[] = $parent;
            foreach ($this->interfaces as $owner => $interfaces) if (strcasecmp($owner, $next) === 0) array_push($queue, ...$interfaces);
        }
        return null;
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
            $class = $this->parentClass($class) ?? '';
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
