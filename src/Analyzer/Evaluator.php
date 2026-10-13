<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Span;
use PhpParser\Node;

/** Conservative control-flow and call summary over one Mago source generation. */
final class Evaluator
{
    private int $visits = 0;
    private int $uncertainCallback = 0;
    private int $boundDepth = 0;
    private bool $consumeGenerator = false;
    private array $consumed = [];

    public function __construct(private readonly Program $program, private readonly bool $assumeGlobalScalarBuiltins = false) {}

    /** @param list<string> $stack */
    public function method(string $class, string $name, array $stack = [], array $arguments = []): Estimate
    {
        if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once dirname(__DIR__) . '/NativeEngine.php';
        if ($stack === [] && \ByteKitsune\MagoNative\Engine::enabled()) return NativeBudget::estimate($this->program, $class, $name, $this->assumeGlobalScalarBuiltins);
        if (++$this->visits > 25_000) return Estimate::unknown('analysis work limit');
        $key = Program::key($class, $name);
        $model = $this->program->method($class, $name);
        if ($model === null) return Estimate::unknown('unresolved method ' . $class . '::' . $name);
        if ($model['node']->stmts === null) return Estimate::unknown('unresolved abstract method ' . $class . '::' . $name);
        if (in_array($key, $stack, true)) {
            if ($this->uncertainCallback > 0) return Estimate::unknown("callback recursion has unknown invocation bound: $key");
            if (end($stack) === $key && $this->hasProvenBreaker($model['node'], $name)) {
                return Estimate::unknown('guarded recursion has unknown invocation bound: ' . $key);
            }
            return new Estimate(0, null, ['unbounded recursion: ' . $key], [$key]);
        }
        if (count($stack) >= 48) return Estimate::unknown('call depth limit');
        if ($this->hasYield($model['node'])) { if (!$this->consumeGenerator) return new Estimate(); $this->consumeGenerator = false; }
        $env = ['this' => $class];
        foreach ($model['node']->params as $parameter) {
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || !$parameter->type instanceof Node\Name) continue;
            $env[$parameter->var->name] = Program::name($parameter->type);
        }
        $this->bindParameters($model['node'], $arguments, $env, $model['class'] ?? '', $model['file']);
        foreach ($arguments as $argument) if (($argument['argument_name'] ?? null) === "\0this") { if (isset($argument['object'])) $env['this'] = $argument['object']; $env["\0values"]['this'] = $argument; }
        return $this->statements($model['node']->stmts ?? [], $model['class'], $model['file'], $env, [...$stack, $key]);
    }

    /** @param list<string> $stack */
    public function functionCall(string $name, array $stack = [], array $arguments = []): Estimate
    {
        if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once dirname(__DIR__) . '/NativeEngine.php';
        if ($stack === [] && \ByteKitsune\MagoNative\Engine::enabled()) return NativeBudget::estimate($this->program, '', '', $this->assumeGlobalScalarBuiltins, $name);
        if (++$this->visits > 25_000) return Estimate::unknown('analysis work limit');
        $model = $this->program->functionModel($name);
        if ($model === null) return Estimate::unknown('unresolved function ' . $name);
        $key = 'function:' . strtolower($model['name']);
        if (in_array($key, $stack, true)) {
            if ($this->uncertainCallback > 0) return Estimate::unknown("callback recursion has unknown invocation bound: $key");
            if (end($stack) === $key && $this->hasProvenFunctionBreaker($model['node'], $model['name'])) {
                return Estimate::unknown('guarded recursion has unknown invocation bound: ' . $key);
            }
            return new Estimate(0, null, ['unbounded recursion: ' . $key], [$key]);
        }
        if (count($stack) >= 48) return Estimate::unknown('call depth limit');
        if ($this->hasYield($model['node'])) { if (!$this->consumeGenerator) return new Estimate(); $this->consumeGenerator = false; }
        $env = [];
        foreach ($model['node']->params as $parameter) {
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || !$parameter->type instanceof Node\Name) continue;
            $env[$parameter->var->name] = Program::name($parameter->type);
        }
        $this->bindParameters($model['node'], $arguments, $env, $model['class'] ?? '', $model['file']);
        return $this->statements($model['node']->stmts, '', $model['file'], $env, [...$stack, $key]);
    }

    /** @param list<Node\Stmt> $statements @param array<string, string> $env @param list<string> $stack */
    private function statements(array $statements, string $class, string $file, array $env, array $stack): Estimate
    {
        $sum = new Estimate();
        $earlyExitLower = null;
        $exceptionExitLower = null;
        foreach ($statements as $statement) {
            if (++$this->visits > 25_000) return $sum->plus(Estimate::unknown('analysis work limit'));
            if ($statement instanceof Node\Stmt\If_) {
                $condition = $this->expression($statement->cond, $class, $file, $env, $stack);
                $branch = $this->statements($statement->stmts, $class, $file, $env, $stack);
                $alternative = $statement->else === null ? new Estimate() : $this->statements($statement->else->stmts, $class, $file, $env, $stack);
                foreach ($statement->elseifs as $elseif) {
                    $alternative = $alternative->branch($this->expression($elseif->cond, $class, $file, $env, $stack)->plus($this->statements($elseif->stmts, $class, $file, $env, $stack)));
                }
                $sum = $sum->plus($condition)->plus($branch->branch($alternative));
                $this->invalidateAssignments($statement, $env);
                $hasExit = $this->containsDirectExit($statement->stmts)
                    || $statement->else !== null && $this->containsDirectExit($statement->else->stmts);
                foreach ($statement->elseifs as $elseif) $hasExit = $hasExit || $this->containsDirectExit($elseif->stmts);
                if ($hasExit) {
                    $earlyExitLower ??= $sum->lower;
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\TryCatch) {
                // A call can throw at any point in the try body. The catch may
                // then execute, so add its worst branch to the full try upper
                // bound. No query in the try or a catch is guaranteed.
                $exceptionExitLower ??= $sum->lower;
                $try = $this->statements($statement->stmts, $class, $file, $env, $stack);
                $catches = new Estimate();
                foreach ($statement->catches as $catch) {
                    $catchEnv = $env;
                    if ($catch->var instanceof Node\Expr\Variable && is_string($catch->var->name)) {
                        unset($catchEnv[$catch->var->name], $catchEnv["\0values"][$catch->var->name]);
                    }
                    $catches = $catches->branch($this->statements($catch->stmts, $class, $file, $catchEnv, $stack));
                }
                $body = $try->plus($catches);
                $sum = $sum->plus(new Estimate(0, $body->upper, $body->unknown, $body->cycles));
                if ($statement->finally !== null) {
                    $sum = $sum->plus($this->statements($statement->finally->stmts, $class, $file, $env, $stack));
                }
                $this->invalidateAssignments($statement, $env);
                continue;
            }
            if ($statement instanceof Node\Stmt\Foreach_) {
                $sum = $sum->plus($this->expression($statement->expr, $class, $file, $env, $stack));
                $iterable = $this->bound($statement->expr, $class, $file, $env);
                if (!isset($iterable['count'])) {
                    $consumption = $this->consume($iterable, $stack, $file);
                    $sum = $sum->plus(new Estimate(0, $consumption->upper, $consumption->unknown, $consumption->cycles));
                }
                if ($statement->byRef) {
                    $this->invalidateTarget($statement->expr, $env);
                    $sum = $sum->plus(Estimate::unknown('reference foreach at ' . $file . ':' . $statement->getStartLine()));
                }
                $bodyEnv = $env;
                $this->invalidateTarget($statement->valueVar, $bodyEnv);
                $this->invalidateTarget($statement->keyVar, $bodyEnv);
                $body = $this->statements($statement->stmts, $class, $file, $bodyEnv, $stack);
                $count = $this->bound($statement->expr, $class, $file, $env)['count'] ?? null;
                if ($count !== null) {
                    $sum = $sum->plus($count === 0 ? new Estimate() : $body->times($count));
                } elseif ($body->upper !== 0 || $body->unknown !== []) {
                    $sum = $sum->plus(Estimate::unknown('unbounded foreach at ' . $file . ':' . $statement->getStartLine()));
                }
                $this->invalidateAssignments($statement, $env);
                continue;
            }
            if ($statement instanceof Node\Stmt\While_ || $statement instanceof Node\Stmt\Do_ || $statement instanceof Node\Stmt\For_) {
                $sum = $sum->plus(Estimate::unknown('unbounded loop at ' . $file . ':' . $statement->getStartLine()));
                $this->invalidateAssignments($statement, $env);
                continue;
            }
            if ($statement instanceof Node\Stmt\Expression) {
                $sum = $sum->plus($this->expression($statement->expr, $class, $file, $env, $stack));
                continue;
            }
            if ($statement instanceof Node\Stmt\Return_) {
                if ($statement->expr !== null) $sum = $sum->plus($this->expression($statement->expr, $class, $file, $env, $stack));
                break;
            }
            if ($statement instanceof Node\Stmt\Nop) continue;
            $sum = $sum->plus(Estimate::unknown('unsupported statement ' . $statement::class . ' at ' . $file . ':' . $statement->getStartLine()));
        }
        if ($earlyExitLower === null) {
            return $exceptionExitLower === null ? $sum : new Estimate(
                min($sum->lower, $exceptionExitLower), $sum->upper, $sum->unknown, $sum->cycles,
            );
        }
        return new Estimate(
            min($sum->lower, $earlyExitLower, $exceptionExitLower ?? $sum->lower),
            $sum->upper,
            $sum->unknown,
            $sum->cycles,
        );
    }

    /** @param list<Node\Stmt> $statements */
    private function containsDirectExit(array $statements): bool
    {
        foreach ($statements as $statement) if ($statement instanceof Node\Stmt\Return_ || $statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Throw_) return true;
        return false;
    }

    /** @param array<string, string> $env @param list<string> $stack */
    private function expression(Node\Expr $expr, string $class, string $file, array &$env, array $stack): Estimate
    {
        if (++$this->visits > 25_000) return Estimate::unknown('analysis work limit');
        if ($expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction) return new Estimate();
        if ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch) return $this->expression($expr->var, $class, $file, $env, $stack)->plus($this->propertyEffect($expr, false, $class, $file, $env));
        if ($expr instanceof Node\Expr\Clone_) return $this->expression($expr->expr, $class, $file, $env, $stack)->plus(Estimate::unknown('object clone hooks at ' . $file));
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $effect = $this->expression($expr->left, $class, $file, $env, $stack);
            $left = $this->bound($expr->left, $class, $file, $env);
            $effect = $effect->plus($this->expression($expr->right, $class, $file, $env, $stack));
            return ($left['scalar'] ?? false) && ($this->bound($expr->right, $class, $file, $env)['scalar'] ?? false) ? $effect : $effect->plus(Estimate::unknown('object string conversion at ' . $file));
        }
        if ($expr instanceof Node\Expr\AssignOp\Concat || $expr instanceof Node\Scalar\InterpolatedString) { if ($expr instanceof Node\Expr\AssignOp\Concat) $this->invalidateTarget($expr->var, $env); return Estimate::unknown('string conversion hooks at ' . $file); }
        if ($expr instanceof Node\Expr\Cast\String_) {
            $effect = $this->expression($expr->expr, $class, $file, $env, $stack);
            return ($this->bound($expr->expr, $class, $file, $env)['scalar'] ?? false) ? $effect : $effect->plus(Estimate::unknown('object string conversion at ' . $file));
        }
        if ($expr instanceof Node\Expr\YieldFrom) {
            $effect = $this->expression($expr->expr, $class, $file, $env, $stack);
            return $effect->plus($this->consume($this->bound($expr->expr, $class, $file, $env), $stack, $file));
        }
        if ($expr instanceof Node\Expr\BinaryOp\Pipe) {
            $sum = $this->expression($expr->left, $class, $file, $env, $stack);
            $argument = $this->bound($expr->left, $class, $file, $env);
            $sum = $sum->plus($this->expression($expr->right, $class, $file, $env, $stack));
            return $sum->plus($this->invoke($this->bound($expr->right, $class, $file, $env)['callable'] ?? null, [$argument], $stack, $file));
        }
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            $sum = $this->expression($expr->var, $class, $file, $env, $stack);
            if ($expr->isFirstClassCallable()) {
                return $expr->name instanceof Node\Expr ? $sum->plus($this->expression($expr->name, $class, $file, $env, $stack)) : $sum;
            }
            $selected = $this->bound($expr->var, $class, $file, $env);
            [$argumentEffects, $arguments] = $this->evaluateArguments($expr, $class, $file, $env, $stack);
            $sum = $sum->plus($argumentEffects);
            if (!$expr->name instanceof Node\Identifier) return $sum->plus(Estimate::unknown('dynamic method call at ' . $file . ':' . $expr->getStartLine()));
            if (($effect = $this->specialMethod($selected, $expr->name->toString(), $arguments, $stack, $file)) !== null) return $sum->plus($effect);
            if (strtolower($expr->name->toString()) === '__invoke' && ($target = $selected['callable'] ?? null) !== null) {
                $effect = $this->invoke($target, $arguments, $stack, $file);
                return $sum->plus($expr instanceof Node\Expr\NullsafeMethodCall ? $effect->branch(new Estimate()) : $effect);
            }
            $receiver = $selected['object'] ?? null;
            if ($receiver === null) return $sum->plus(Estimate::unknown('unknown receiver for ' . $expr->name . ' at ' . $file . ':' . $expr->getStartLine()));
            $arguments[] = [...$selected, 'argument_name' => "\0this"];
            $effect = $this->call($receiver, $expr->name->toString(), $class, $file, $stack, $arguments);
            return $sum->plus($expr instanceof Node\Expr\NullsafeMethodCall ? $effect->branch(new Estimate()) : $effect);
        }
        if ($expr instanceof Node\Expr\StaticCall) {
            $sum = new Estimate();
            if ($expr->isFirstClassCallable()) {
                foreach ([$expr->class, $expr->name] as $selector) if ($selector instanceof Node\Expr) $sum = $sum->plus($this->expression($selector, $class, $file, $env, $stack));
                return $sum;
            }
            [$argumentEffects, $arguments] = $this->evaluateArguments($expr, $class, $file, $env, $stack);
            $sum = $sum->plus($argumentEffects);
            if ($expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
                $scope = strtolower($expr->class->toString());
                $receiver = match ($scope) {
                    'self' => $class === '' ? null : $class,
                    'parent' => $this->program->parents[$class] ?? null,
                    'static' => null,
                    default => Program::name($expr->class),
                };
                if ($receiver === null) return $sum->plus(Estimate::unknown('unresolved static scope ' . $scope . ' at ' . $file . ':' . $expr->getStartLine()));
                return $sum->plus($this->call($receiver, $expr->name->toString(), $class, $file, $stack, $arguments));
            }
            return $sum->plus(Estimate::unknown('dynamic static call at ' . $file . ':' . $expr->getStartLine()));
        }
        if ($expr instanceof Node\Expr\AssignRef) {
            $sum = $this->expression($expr->expr, $class, $file, $env, $stack);
            $this->invalidateTarget($expr->var, $env); $this->invalidateTarget($expr->expr, $env);
            return $sum->plus(Estimate::unknown('reference alias assignment at ' . $file . ':' . $expr->getStartLine()));
        }
        if ($expr instanceof Node\Expr\Assign) {
            $sum = new Estimate();
            if ($expr->var instanceof Node\Expr\PropertyFetch) $sum = $sum->plus($this->expression($expr->var->var, $class, $file, $env, $stack))->plus($this->propertyEffect($expr->var, true, $class, $file, $env));
            $sum = $sum->plus($this->expression($expr->expr, $class, $file, $env, $stack));
            if ($expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                $value = $this->bound($expr->expr, $class, $file, $env);
                unset($env[$expr->var->name]);
                if (isset($value['object'])) $env[$expr->var->name] = $value['object'];
                $env["\0values"][$expr->var->name] = $value;
            } else $this->invalidateTarget($expr->var, $env);
            return $sum;
        }
        if ($expr instanceof Node\Expr\Ternary) {
            $condition = $this->expression($expr->cond, $class, $file, $env, $stack);
            $yesEnv = $env; $noEnv = $env;
            $yes = $expr->if === null ? new Estimate() : $this->expression($expr->if, $class, $file, $yesEnv, $stack);
            $no = $this->expression($expr->else, $class, $file, $noEnv, $stack);
            $this->invalidateAssignments($expr, $env);
            return $condition->plus($yes->branch($no));
        }
        if ($expr instanceof Node\Expr\New_) {
            $sum = new Estimate();
            [$argumentEffects, $arguments] = $this->evaluateArguments($expr, $class, $file, $env, $stack);
            $sum = $sum->plus($argumentEffects);
            if ($expr->class instanceof Node\Name && $this->program->method(Program::name($expr->class), '__construct') !== null) {
                return $sum->plus($this->method(Program::name($expr->class), '__construct', $stack, $arguments));
            }
            return $sum;
        }
        if ($expr instanceof Node\Expr\FuncCall) {
            if ($expr->isFirstClassCallable()) return $expr->name instanceof Node\Expr ? $this->expression($expr->name, $class, $file, $env, $stack) : new Estimate();
            $sum = $expr->name instanceof Node\Expr ? $this->expression($expr->name, $class, $file, $env, $stack) : new Estimate();
            $selected = $expr->name instanceof Node\Expr ? $this->bound($expr->name, $class, $file, $env) : [];
            [$argumentEffects, $arguments] = $this->evaluateArguments($expr, $class, $file, $env, $stack);
            $sum = $sum->plus($argumentEffects);
            if (!$expr->name instanceof Node\Name) return $sum->plus($this->invoke($selected['callable'] ?? null, $arguments, $stack, $file));
            $namespaced = $expr->name->getAttribute('namespacedName');
            $candidates = $namespaced instanceof Node\Name
                ? [Program::name($namespaced), $expr->name->toString()]
                : [Program::name($expr->name)];
            foreach ($candidates as $candidate) {
                if (isset($this->program->ambiguousFunctions[strtolower($candidate)])) {
                    return $sum->plus(Estimate::unknown('ambiguous function call ' . $candidate . ' at ' . $file . ':' . $expr->getStartLine()));
                }
                if ($this->program->functionModel($candidate) !== null) return $sum->plus($this->functionCall($candidate, $stack, $arguments));
            }
            if (($effect = $this->extendedBuiltin($expr, $file, $env, $stack, $arguments)) !== null) return $sum->plus($effect);
            $effect = $this->callbackBuiltin($expr, $class, $file, $env, $stack, $arguments);
            if ($effect !== null) return $sum->plus($effect);
            if ($this->isScalarBuiltin($expr, $class, $file, $env, $arguments) || $this->isPureArrayBuiltin($expr, $class)) return $sum;
            $name = implode(' or ', $candidates);
            return $sum->plus(Estimate::unknown('unresolved function call ' . $name . ' at ' . $file . ':' . $expr->getStartLine()));
        }
        if ($expr instanceof Node\Expr\AssignOp) $this->invalidateTarget($expr->var, $env);
        $sum = new Estimate();
        foreach ($expr->getSubNodeNames() as $name) {
            $value = $expr->$name;
            if ($value instanceof Node\Expr) $sum = $sum->plus($this->expression($value, $class, $file, $env, $stack));
            if (is_array($value)) foreach ($value as $part) {
                if ($part instanceof Node\Expr) $sum = $sum->plus($this->expression($part, $class, $file, $env, $stack));
                if ($part instanceof Node\Arg) $sum = $sum->plus($this->expression($part->value, $class, $file, $env, $stack));
                if ($part instanceof Node\Expr\ArrayItem) $sum = $sum->plus($this->expression($part->value, $class, $file, $env, $stack));
            }
        }
        return $sum;
    }

    private function evaluateArguments(Node\Expr $node, string $class, string $file, array &$env, array $stack): array
    {
        $model = $this->argumentModel($node, $class, $file, $env);
        $invalidated = [];
        $sum = new Estimate(); $values = [];
        foreach ($node->args as $index => $argument) {
            if (!$argument instanceof Node\Arg) continue;
            $sum = $sum->plus($this->expression($argument->value, $class, $file, $env, $stack));
            $values[] = [...$this->bound($argument->value, $class, $file, $env), 'argument_name' => $argument->unpack ? '*' : $argument->name?->toString()];
            $parameter = $model?->params[$index] ?? null;
            if ($argument->name !== null) foreach ($model?->params ?? [] as $candidate) if ($candidate->var instanceof Node\Expr\Variable && $candidate->var->name === $argument->name->toString()) $parameter = $candidate;
            if ($parameter?->byRef && $argument->value instanceof Node\Expr\Variable && is_string($argument->value->name)) $invalidated[] = $argument->value->name;
        }
        foreach ($invalidated as $name) unset($env[$name], $env["\0values"][$name]);
        return [$sum, $values];
    }

    private function argumentModel(Node\Expr $node, string $class, string $file, array $env): ?Node
    {
        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
            $owner = $this->receiver($node->var, $class, $file, $env);
            return $owner === null ? null : ($this->program->method($owner, $node->name->toString())['node'] ?? null);
        }
        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier || $node instanceof Node\Expr\New_) {
            $owner = $this->className($node->class, $class);
            return $owner === null ? null : ($this->program->method($owner, $node instanceof Node\Expr\New_ ? '__construct' : $node->name->toString())['node'] ?? null);
        }
        if (!$node instanceof Node\Expr\FuncCall) return null;
        if ($node->name instanceof Node\Name) {
            $ns = $node->name->getAttribute('namespacedName');
            return ($ns instanceof Node\Name ? $this->program->functionModel(Program::name($ns))['node'] ?? null : null) ?? ($this->program->functionModel(Program::name($node->name))['node'] ?? null);
        }
        $target = $this->bound($node->name, $class, $file, $env)['callable'] ?? null;
        return match ($target?->kind) {
            'closure' => $target->node,
            'method' => $this->program->method($target->owner, $target->name)['node'] ?? null,
            'function' => $this->program->functionModel($target->name)['node'] ?? null,
            default => null,
        };
    }

    private function containsAssignment(mixed $value): bool
    {
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) return false;
        if ($value instanceof Node\Expr\Assign || $value instanceof Node\Expr\AssignOp) return true;
        if ($value instanceof Node) foreach ($value->getSubNodeNames() as $key) { if ($this->containsAssignment($value->$key)) return true; }
        elseif (is_array($value)) foreach ($value as $node) { if ($this->containsAssignment($node)) return true; }
        return false;
    }

    /** Abstract values are local to an invocation; callable descriptors are immutable objects. */
    private function boundArgs(Node\Expr $node, string $class, string $file, array $env): array
    {
        foreach ($node->args as $arg) if (!$arg instanceof Node\Arg || $arg->unpack) return [['argument_name' => '*']];
        return array_map(fn (Node\Arg $arg): array => [...$this->bound($arg->value, $class, $file, $env), 'argument_name' => $arg->name?->toString()], $node->args);
    }

    private function bound(Node\Expr $node, string $class, string $file, array $env): array
    {
        if ($this->boundDepth >= 48) return [];
        ++$this->boundDepth;
        try { return $this->boundInner($node, $class, $file, $env); }
        finally { --$this->boundDepth; }
    }

    private function boundInner(Node\Expr $node, string $class, string $file, array $env): array
    {
        if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $holder = $this->bound($node->var, $class, $file, $env);
            if (isset($holder['properties'][$node->name->toString()])) return $holder['properties'][$node->name->toString()];
        }
        if ($node instanceof Node\Expr\Assign) return $node->var instanceof Node\Expr\Variable && is_string($node->var->name) && isset($env["\0values"][$node->var->name]) ? $env["\0values"][$node->var->name] : $this->bound($node->expr, $class, $file, $env);
        if ($node instanceof Node\Expr\Array_ && $this->containsAssignment($node)) return ['count' => $this->literalCount($node)];
        if ((!method_exists($node, 'isFirstClassCallable') || !$node->isFirstClassCallable()) && !$this->containsAssignment($node->args ?? []) && ($special = $this->specialBound($node, $class, $file, $env)) !== null) return $special;
        $constant = null;
        if ($node instanceof Node\Expr\ClassConstFetch && $node->name instanceof Node\Identifier) {
            $owner = $this->className($node->class, $class);
            if ($owner !== null) $constant = strtolower($owner) . '::' . $node->name->toString();
        } elseif ($node instanceof Node\Expr\ConstFetch) {
            $ns = $node->name->getAttribute('namespacedName');
            $constant = $ns instanceof Node\Name && isset($this->program->constants[Program::name($ns)]) ? Program::name($ns) : Program::name($node->name);
        }
        if ($constant !== null && isset($this->program->constants[$constant])) {
            $model = $this->program->constants[$constant];
            return $this->bound($model['node'], $model['class'], $model['file'], []);
        }
        if ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($env["\0values"][$node->name])) return [...$env["\0values"][$node->name], 'argument_name' => null];
        if ($node instanceof Node\Expr\StaticCall && !$node->isFirstClassCallable() && strcasecmp($this->className($node->class, $class) ?? '', 'Closure') === 0 && $node->name instanceof Node\Identifier && strcasecmp($node->name->toString(), 'fromCallable') === 0 && count($node->args) === 1 && $node->args[0] instanceof Node\Arg && !$node->args[0]->unpack) return $this->bound($node->args[0]->value, $class, $file, $env);
        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && !$node->isFirstClassCallable() && !$this->containsAssignment($node->args) && $node->name instanceof Node\Identifier) {
            $owner = $node instanceof Node\Expr\MethodCall ? $this->receiver($node->var, $class, $file, $env) : $this->className($node->class, $class);
            $model = $owner === null ? null : $this->program->method($owner, $node->name->toString());
            if ($model !== null && count($model['node']->stmts ?? []) === 1 && $model['node']->stmts[0] instanceof Node\Stmt\Return_ && $model['node']->stmts[0]->expr !== null) {
                $scope = ['this' => $model['class']];
                $this->bindParameters($model['node'], $this->boundArgs($node, $class, $file, $env), $scope, $model['class'], $model['file']);
                $result = $this->bound($model['node']->stmts[0]->expr, $model['class'], $model['file'], $scope);
                if (isset($result['callable'])) return $result;
            }
        }
        $callback = null;
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $scope = [];
            if ($node instanceof Node\Expr\ArrowFunction) {
                $variables = []; $this->variables($node->expr, $variables);
                foreach ($variables as $key) {
                    if (isset($env[$key])) $scope[$key] = $env[$key];
                    if (isset($env["\0values"][$key])) $scope["\0values"][$key] = $env["\0values"][$key];
                }
            }
            if (!$node->static && isset($env['this'])) $scope['this'] = $env['this'];
            if ($node->static) unset($scope['this'], $scope["\0values"]['this']);
            foreach ($node instanceof Node\Expr\Closure ? $node->uses : [] as $capture) {
                if ($capture->byRef) return [];
                $key = $capture->var->name;
                if (isset($env[$key])) $scope[$key] = $env[$key];
                if (isset($env["\0values"][$key])) $scope["\0values"][$key] = $env["\0values"][$key];
            }
            $callback = (object) ['kind' => 'closure', 'node' => $node, 'scope' => $scope, 'class' => $class, 'file' => $file];
        } elseif ($node instanceof Node\Expr\MethodCall && $node->isFirstClassCallable() && $node->name instanceof Node\Identifier) {
            $owner = $this->receiver($node->var, $class, $file, $env);
            if ($owner !== null) $callback = (object) ['kind' => 'method', 'owner' => $owner, 'name' => $node->name->toString()];
        } elseif ($node instanceof Node\Expr\StaticCall && $node->isFirstClassCallable() && $node->name instanceof Node\Identifier) {
            $owner = $this->className($node->class, $class);
            if ($owner !== null) $callback = (object) ['kind' => 'method', 'owner' => $owner, 'name' => $node->name->toString()];
        } elseif ($node instanceof Node\Expr\FuncCall && $node->isFirstClassCallable()) {
            if ($node->name instanceof Node\Name) {
                $ns = $node->name->getAttribute('namespacedName');
                $name = $ns instanceof Node\Name ? Program::name($ns) : Program::name($node->name);
                $callback = (object) ['kind' => 'function', 'name' => $name];
            } else $callback = $this->bound($node->name, $class, $file, $env)['callable'] ?? null;
        } elseif ($node instanceof Node\Scalar\String_) {
            $name = ltrim($node->value, '\\');
            if (str_contains($name, '::')) {
                [$owner, $method] = explode('::', $name, 2);
                $callback = (object) ['kind' => 'method', 'owner' => $owner, 'name' => $method];
            } else $callback = (object) ['kind' => 'function', 'name' => $name];
        } elseif ($node instanceof Node\Expr\Array_ && $this->literalCount($node) === 2) {
            $owner = $node->items[0]->value; $method = $node->items[1]->value;
            $receiver = $owner instanceof Node\Scalar\String_ ? $owner->value : (
                $owner instanceof Node\Expr\ClassConstFetch && $owner->name instanceof Node\Identifier && strtolower($owner->name->toString()) === 'class'
                    ? $this->className($owner->class, $class) : $this->receiver($owner, $class, $file, $env)
            );
            if ($receiver !== null && $method instanceof Node\Scalar\String_) $callback = (object) ['kind' => 'method', 'owner' => $receiver, 'name' => $method->value];
        }
        $object = $this->receiver($node, $class, $file, $env);
        if ($callback === null && $object !== null && $this->program->method($object, '__invoke') !== null) $callback = (object) ['kind' => 'method', 'owner' => $object, 'name' => '__invoke'];
        $count = $this->literalCount($node);
        $element = null;
        if ($count !== null && $count > 0) {
            $items = array_map(fn ($item): array => $this->bound($item->value, $class, $file, $env), $node->items);
            $sameObject = $items[0]['object'] ?? null;
            $scalar = true;
            foreach ($items as $item) { $scalar = $scalar && ($item['scalar'] ?? false); if (($item['object'] ?? null) !== $sameObject) $sameObject = null; }
            $element = ['object' => $sameObject, 'scalar' => $scalar];
        }
        $scalar = $node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_ || $node instanceof Node\Expr\ConstFetch && in_array(strtolower(Program::name($node->name)), ['null', 'true', 'false'], true);
        if (!$scalar && $node instanceof Node\Expr\Variable && ($analysis = $this->program->analysis->getFile($file)) !== null && $node->getStartFilePos() >= 0) {
            $type = $analysis->getExpressionType(new Span($node->getStartFilePos(), $node->getEndFilePos() + 1));
            $scalar = $type !== null;
            foreach ($type?->atomicTypes ?? [] as $atomic) if (!$atomic instanceof ScalarType || !in_array($atomic->kind, [ScalarTypeKind::Boolean, ScalarTypeKind::Integer, ScalarTypeKind::Float, ScalarTypeKind::String], true)) $scalar = false;
        }
        $members = null;
        if ($node instanceof Node\Expr\Array_) {
            $members = []; $keys = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack || $item->byRef || $item->key !== null && !$item->key instanceof Node\Scalar\String_) { $members = null; break; }
                if ($item->key !== null) { if (is_numeric($item->key->value) || in_array($item->key->value, $keys, true)) { $members = null; break; } $keys[] = $item->key->value; }
                $members[] = [...$this->bound($item->value, $class, $file, $env), 'argument_name' => $item->key?->value];
            }
        }
        return ['object' => $object, 'callable' => $callback, 'count' => $count, 'scalar' => $scalar, 'element' => $element, 'members' => $members];
    }

    private function className(Node $node, string $class): ?string
    {
        if (!$node instanceof Node\Name) return null;
        return match (strtolower(Program::name($node))) {
            'self' => $class === '' ? null : $class,
            'parent' => $this->program->parentClass($class),
            'static' => null,
            default => Program::name($node),
        };
    }

    private function invoke(?object $target, array $arguments, array $stack, string $file): Estimate
    {
        if ($target === null) return Estimate::unknown('unresolved callback invocation at ' . $file);
        if ($target->kind === 'method') { if (!in_array("\0this", array_column($arguments, 'argument_name'), true)) $arguments[] = ['object' => $target->owner, 'argument_name' => "\0this"]; return $this->call($target->owner, $target->name, '', $file, $stack, $arguments); }
        if ($target->kind === 'function') {
            $builtin = strtolower($target->name);
            if ($this->program->functionModel($builtin) === null && !isset($this->program->ambiguousFunctions[$builtin]) && $arguments !== [] && in_array($builtin, ['mb_trim', 'max', 'min', 'trim', 'strlen', 'strtolower', 'strtoupper', 'intval', 'floatval', 'boolval', 'strval'], true)) {
                $scalar = true; foreach ($arguments as $argument) $scalar = $scalar && ($argument['scalar'] ?? false);
                if ($scalar) return new Estimate();
            }
            return $this->functionCall($target->name, $stack, $arguments);
        }
        if ($this->hasYield($target->node)) { if (!$this->consumeGenerator) return new Estimate(); $this->consumeGenerator = false; }
        $key = 'closure:' . $target->file . ':' . $target->node->getStartFilePos();
        if (in_array($key, $stack, true)) return Estimate::unknown('callback recursion has unknown invocation bound: ' . $key);
        if (count($stack) >= 48) return Estimate::unknown('call depth limit');
        $scope = $target->scope;
        $this->bindParameters($target->node, $arguments, $scope, $target->class, $target->file);
        return $target->node instanceof Node\Expr\ArrowFunction
            ? $this->expression($target->node->expr, $target->class, $target->file, $scope, [...$stack, $key])
            : $this->statements($target->node->stmts, $target->class, $target->file, $scope, [...$stack, $key]);
    }

    private function propertyEffect(Node\Expr $node, bool $write, string $class, string $file, array $env): Estimate
    {
        $owner = $this->receiver($node->var, $class, $file, $env);
        if ($owner === null) return Estimate::unknown('unresolved property hooks at ' . $file);
        $property = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
        for ($depth = 0; $owner !== null && $depth < 48; ++$depth) {
            if (in_array($property, $this->program->propertyHooks[$owner] ?? [], true) || $this->program->method($owner, $write ? '__set' : '__get') !== null || $this->program->method($owner, '__isset') !== null || $this->program->method($owner, '__unset') !== null) return Estimate::unknown('property or magic access hooks for ' . $owner . '::' . $property . ' at ' . $file);
            $owner = $this->program->parentClass($owner);
        }
        return new Estimate();
    }
    private function selectedCallable(Node\Expr $node, string $class, string $file, array $env): ?object
    {
        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier) {
            $owner = $node instanceof Node\Expr\MethodCall ? $this->receiver($node->var, $class, $file, $env) : $this->className($node->class, $class);
            return $owner === null ? null : (object) ['kind' => 'method', 'owner' => $owner, 'name' => $node->name->toString()];
        }
        if ($node instanceof Node\Expr\FuncCall) {
            if (!$node->name instanceof Node\Name) return $this->bound($node->name, $class, $file, $env)['callable'] ?? null;
            $ns = $node->name->getAttribute('namespacedName');
            $name = $ns instanceof Node\Name && $this->program->functionModel(Program::name($ns)) !== null ? Program::name($ns) : Program::name($node->name);
            return (object) ['kind' => 'function', 'name' => $name];
        }
        return null;
    }
    private function specialBound(Node\Expr $node, string $class, string $file, array $env): ?array
    {
        $target = $this->selectedCallable($node, $class, $file, $env);
        $model = $target === null ? null : match ($target->kind) {
            'method' => $this->program->method($target->owner, $target->name)['node'] ?? null,
            'function' => $this->program->functionModel($target->name)['node'] ?? null,
            default => $target->node,
        };
        if ($model !== null && $this->hasYield($model)) return ['object' => 'Generator', 'deferred' => (object) ['target' => $target, 'arguments' => $this->boundArgs($node, $class, $file, $env)]];
        if (($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) && $node->name instanceof Node\Identifier) {
            $method = strtolower($node->name->toString()); $args = $this->boundArgs($node, $class, $file, $env); $target = null;
            if ($node instanceof Node\Expr\StaticCall && strcasecmp($this->className($node->class, $class) ?? '', 'Closure') === 0 && $method === 'bind') $target = $this->argument($args, 0, 'closure');
            elseif ($node instanceof Node\Expr\MethodCall && $method === 'bindto') $target = $this->bound($node->var, $class, $file, $env);
            if ($target !== null) { $position = $node instanceof Node\Expr\StaticCall ? 1 : 0; if ($this->argument($args, $position + 1, 'newScope') !== null) return []; return $this->rebind($target, $this->argument($args, $position, 'newThis')); }
        }
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $owner = $this->className($node->class, $class); $args = $this->boundArgs($node, $class, $file, $env); $reflection = null;
            if (strcasecmp($owner ?? '', 'ReflectionFunction') === 0) $reflection = $this->argument($args, 0, 'function')['callable'] ?? null;
            elseif (strcasecmp($owner ?? '', 'ReflectionMethod') === 0) {
                $first = $node->args[0]->value ?? null;
                $type = $this->argument($args, 0, 'objectOrMethod')['object'] ?? ($first instanceof Node\Scalar\String_ ? $first->value : ($first instanceof Node\Expr\ClassConstFetch ? $this->className($first->class, $class) : null));
                $method = $node->args[1]->value ?? null;
                if ($type !== null && $method instanceof Node\Scalar\String_) $reflection = (object) ['kind' => 'method', 'owner' => $type, 'name' => $method->value];
            } else {
                $constructor = $this->program->method($owner, '__construct');
                if ($constructor === null || $constructor['node']->stmts !== []) return null;
                $properties = [];
                foreach ($constructor['node']->params as $index => $parameter) {
                    if (($parameter->flags & 64) === 0 || !$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) continue;
                    $value = $this->argument($args, $index, $parameter->var->name);
                    if ($value !== null) $properties[$parameter->var->name] = $value;
                }
                return ['object' => $owner, 'properties' => $properties];
            }
            return ['object' => $owner, 'reflection' => $reflection];
        }
        return null;
    }
    private function rebind(array $target, ?array $receiver, bool $changeScope = false): array
    {
        $callback = $target['callable'] ?? null;
        if ($callback === null || $callback->kind !== 'closure' || $callback->node->static) return [];
        $callback = clone $callback;
        if ($changeScope) $callback->class = $receiver['object'] ?? '';
        unset($callback->scope['this'], $callback->scope["\0values"]['this']);
        if (isset($receiver['object'])) $callback->scope['this'] = $receiver['object'];
        return ['callable' => $callback];
    }
    private function specialMethod(array $target, string $method, array $args, array $stack, string $file): ?Estimate
    {
        if (isset($target['callable'])) {
            if (strcasecmp($method, 'bindTo') === 0) return new Estimate();
            if (strcasecmp($method, 'call') === 0) return $this->invoke($this->rebind($target, $this->argument($args, 0, 'newThis'), true)['callable'] ?? null, array_slice($args, 1), $stack, $file);
        }
        if (($callback = $target['reflection'] ?? null) !== null) {
            $offset = strcasecmp($target['object'] ?? '', 'ReflectionMethod') === 0 ? 1 : 0;
            $values = match (strtolower($method)) { 'invoke' => array_slice($args, $offset), 'invokeargs' => $this->argument($args, $offset, 'args')['members'] ?? [['argument_name' => '*']], default => null };
            if ($values === null) return null;
            if ($offset === 1 && ($receiver = $this->argument($args, 0, 'object')) !== null) $values[] = [...$receiver, 'argument_name' => "\0this"];
            return $this->invoke($callback, $values, $stack, $file);
        }
        return null;
    }
    private function consume(array $value, array $stack, string $file): Estimate
    {
        if (isset($value['deferred'])) {
            $deferred = $value['deferred'];
            if (in_array($deferred, $this->consumed, true)) return Estimate::unknown('generator already consumed or advanced at ' . $file);
            $this->consumed[] = $deferred; $this->consumeGenerator = true;
            try { return $this->invoke($deferred->target, $deferred->arguments, $stack, $file); }
            finally { $this->consumeGenerator = false; }
        }
        return isset($value['members']) || isset($value['count']) ? new Estimate() : Estimate::unknown('unresolved iterator execution at ' . $file);
    }
    private function repeatedCallback(?object $target, array $args, string $builtin, array $stack, string $file): Estimate
    {
        ++$this->uncertainCallback;
        try { $effect = $this->invoke($target, $args, $stack, $file); }
        finally { --$this->uncertainCallback; }
        return $effect->upper !== 0 || $effect->unknown !== [] ? new Estimate(0, null, [...$effect->unknown, 'unknown callback invocation count for ' . $builtin . ' at ' . $file], $effect->cycles) : $effect;
    }
    private function recursiveWalk(array $value, ?object $callback, array $extra, array $stack, string $file): Estimate
    {
        if (!isset($value['members'])) return $this->repeatedCallback($callback, [[], ['scalar' => true], $extra], 'array_walk_recursive', $stack, $file);
        $result = new Estimate();
        foreach ($value['members'] as $member) {
            if (isset($member['members'])) $effect = $this->recursiveWalk($member, $callback, $extra, $stack, $file);
            elseif (!($member['scalar'] ?? false) && !isset($member['object'])) $effect = $this->repeatedCallback($callback, [[], ['scalar' => true], [...$extra, 'argument_name' => null]], 'array_walk_recursive', $stack, $file);
            else {
                ++$this->uncertainCallback;
                try { $effect = $this->invoke($callback, [[...$member, 'argument_name' => null], ['scalar' => true], [...$extra, 'argument_name' => null]], $stack, $file); }
                finally { --$this->uncertainCallback; }
            }
            $result = $result->plus($effect);
        }
        return $result;
    }
    private function extendedBuiltin(Node\Expr\FuncCall $node, string $file, array &$env, array $stack, array $args): ?Estimate
    {
        $builtin = strtolower(Program::name($node->name)); $ns = $node->name->getAttribute('namespacedName');
        if (!$node->name instanceof Node\Name\FullyQualified && $ns instanceof Node\Name && str_contains(Program::name($ns), '\\')) return null;
        $sort = in_array($builtin, ['usort', 'uasort', 'uksort'], true);
        $compare = in_array($builtin, ['array_udiff', 'array_udiff_assoc', 'array_udiff_uassoc', 'array_uintersect', 'array_uintersect_assoc', 'array_uintersect_uassoc', 'array_diff_uassoc', 'array_diff_ukey', 'array_intersect_uassoc', 'array_intersect_ukey'], true);
        $registration = in_array($builtin, ['register_shutdown_function', 'set_error_handler', 'set_exception_handler', 'spl_autoload_register'], true);
        if (!$sort && !$compare && !$registration && !in_array($builtin, ['array_walk_recursive', 'preg_replace_callback', 'preg_replace_callback_array', 'iterator_apply', 'iterator_count', 'iterator_to_array'], true)) return null;
        if (in_array('*', array_column($args, 'argument_name'), true)) return Estimate::unknown('unresolved callback arguments to ' . $builtin);
        if ($registration) return Estimate::unknown('deferred callback registration via ' . $builtin . ' at ' . $file);
        $first = $this->argument($args, 0, str_starts_with($builtin, 'iterator_') ? 'iterator' : 'array') ?? [];
        if ($sort || $builtin === 'array_walk_recursive') foreach ($node->args as $index => $arg) if ($index === 0 && $arg->name === null || $arg->name?->toString() === 'array') $this->invalidateTarget($arg->value, $env);
        if ($builtin === 'iterator_count' || $builtin === 'iterator_to_array') return $this->consume($first, $stack, $file);
        if ($builtin === 'iterator_apply') {
            $effect = $this->consume($first, $stack, $file);
            return (new Estimate(0, $effect->upper, $effect->unknown, $effect->cycles))->plus($this->repeatedCallback($this->argument($args, 1, 'callback')['callable'] ?? null, $this->argument($args, 2, 'args')['members'] ?? [], $builtin, $stack, $file));
        }
        if ($builtin === 'array_walk_recursive') return $this->recursiveWalk($first, $this->argument($args, 1, 'callback')['callable'] ?? null, $this->argument($args, 2, 'arg') ?? [], $stack, $file);
        if ($sort) {
            if (isset($first['count']) && $first['count'] < 2) return new Estimate();
            $value = $builtin === 'uksort' ? ['scalar' => true] : ($first['element'] ?? []);
            return $this->repeatedCallback($this->argument($args, 1, 'callback')['callable'] ?? null, [$value, $value], $builtin, $stack, $file);
        }
        if ($compare) {
            $count = str_ends_with($builtin, '_uassoc') && (str_starts_with($builtin, 'array_udiff') || str_starts_with($builtin, 'array_uintersect')) ? 2 : 1;
            $effect = new Estimate();
            foreach (array_slice($args, -$count) as $callback) $effect = $effect->plus($this->repeatedCallback($callback['callable'] ?? null, [[], []], $builtin, $stack, $file));
            foreach (array_slice($args, 0, -$count) as $value) if (($value['count'] ?? null) !== 0 && !($value['element']['scalar'] ?? false)) return $effect->plus(Estimate::unknown('opaque comparison values for ' . $builtin . ' at ' . $file));
            return $effect;
        }
        if ($builtin === 'preg_replace_callback_array') {
            $callbacks = $this->argument($args, 0, 'pattern')['members'] ?? null;
            if ($callbacks === null) return Estimate::unknown('unresolved callback map for ' . $builtin . ' at ' . $file);
            $effect = new Estimate();
            foreach ($callbacks as $callback) $effect = $effect->plus($this->repeatedCallback($callback['callable'] ?? null, [[]], $builtin, $stack, $file));
            return $effect->plus(Estimate::unknown('regular expression callback result conversion at ' . $file));
        }
        return $this->repeatedCallback($this->argument($args, 1, 'callback')['callable'] ?? null, [[]], $builtin, $stack, $file)->plus(Estimate::unknown('regular expression callback result conversion at ' . $file));
    }

    private function callbackBuiltin(Node\Expr\FuncCall $node, string $class, string $file, array &$env, array $stack, array $evaluated): ?Estimate
    {
        $builtin = strtolower(Program::name($node->name));
        if (!in_array($builtin, ['call_user_func', 'call_user_func_array', 'array_map', 'array_filter', 'array_walk', 'array_reduce', 'array_any', 'array_all', 'array_find', 'array_find_key'], true)) return null;
        $namespaceName = $node->name->getAttribute('namespacedName');
        if (!$node->name instanceof Node\Name\FullyQualified && $namespaceName instanceof Node\Name && str_contains(Program::name($namespaceName), '\\')) return null;
        foreach ($node->args as $arg) if (!$arg instanceof Node\Arg || $arg->unpack) return Estimate::unknown('unresolved callback arguments to ' . $builtin);
        $original = $node;
        $node = $this->normalizeCallbackArguments($node, $builtin);
        $args = [];
        foreach ($node->args as $argument) {
            $match = null;
            foreach ($original->args as $index => $originalArgument) if ($originalArgument->value === $argument->value) { $match = $evaluated[$index] ?? []; break; }
            $args[] = [...($match ?? []), 'argument_name' => null];
        }
        if ($builtin === 'array_walk' && ($node->args[0]->value ?? null) instanceof Node\Expr\Variable && is_string($node->args[0]->value->name)) unset($env[$node->args[0]->value->name], $env["\0values"][$node->args[0]->value->name]);
        $position = in_array($builtin, ['call_user_func', 'call_user_func_array', 'array_map'], true) ? 0 : 1;
        $target = $args[$position]['callable'] ?? null;
        if ($builtin === 'call_user_func') return $this->invoke($target, array_slice($args, 1), $stack, $file);
        if ($builtin === 'call_user_func_array') return $this->invoke($target, $args[1]['members'] ?? [['argument_name' => '*']], $stack, $file);
        if ($builtin === 'array_filter' && count($args) < 2) return new Estimate();
        $count = $args[$builtin === 'array_map' ? 1 : 0]['count'] ?? null;
        if ($builtin === 'array_map') foreach (array_slice($args, 2) as $arg) $count = $count === null || ($arg['count'] ?? null) === null ? null : max($count, $arg['count']);
        if ($count === 0) return new Estimate();
        if ($builtin === 'array_map' && ($node->args[0]->value ?? null) instanceof Node\Expr\ConstFetch && strtolower(Program::name($node->args[0]->value->name)) === 'null') return new Estimate();
        ++$this->uncertainCallback;
        $values = $builtin === 'array_map' ? array_map(static fn (array $arg): array => $arg['element'] ?? [], array_slice($args, 1)) : ($builtin === 'array_reduce' ? [[], $args[0]['element'] ?? []] : [$args[0]['element'] ?? [], ['scalar' => true]]);
        try { $effect = $this->invoke($target, $values, $stack, $file); }
        finally { --$this->uncertainCallback; }
        if ($count !== null) {
            $effect = $effect->times($count);
            if (in_array($builtin, ['array_any', 'array_all', 'array_find', 'array_find_key'], true)) $effect = new Estimate(0, $effect->upper, $effect->unknown, $effect->cycles);
        } elseif ($effect->upper !== 0 || $effect->unknown !== []) {
            $effect = new Estimate(0, null, [...$effect->unknown, 'unknown callback invocation count for ' . $builtin . ' at ' . $file], $effect->cycles);
        }
        return $effect;
    }

    private function bindParameters(Node $node, array $arguments, array &$env, string $class, string $file): void
    {
        foreach ($node->params as $index => $parameter) {
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) continue;
            $key = $parameter->var->name;
            unset($env[$key], $env["\0values"][$key]);
            if ($parameter->type instanceof Node\Name) $env[$key] = Program::name($parameter->type);
            $value = $this->argument($arguments, $index, $key);
            if ($value === null && $parameter->default !== null && !in_array('*', array_column($arguments, 'argument_name'), true)) $value = $this->bound($parameter->default, $class, $file, []);
            if ($parameter->byRef || $parameter->variadic || $value === null) continue;
            if (isset($value['object'])) $env[$key] = $value['object'];
            $env["\0values"][$key] = $value;
        }
    }

    private function argument(array $arguments, int $index, string $name): ?array
    {
        foreach ($arguments as $argument) if (($argument['argument_name'] ?? null) === $name) return $argument;
        return isset($arguments[$index]) && ($arguments[$index]['argument_name'] ?? null) === null ? $arguments[$index] : null;
    }

    private function normalizeCallbackArguments(Node\Expr\FuncCall $node, string $builtin): Node\Expr\FuncCall
    {
        $names = match ($builtin) {
            'array_map' => ['callback', 'array'], 'array_filter' => ['array', 'callback', 'mode'],
            'array_walk' => ['array', 'callback', 'arg'], 'array_reduce' => ['array', 'callback', 'initial'],
            'call_user_func' => ['callback'], 'call_user_func_array' => ['callback', 'args'], default => ['array', 'callback'],
        };
        $ordered = [];
        foreach ($node->args as $index => $argument) {
            $position = $argument->name === null ? $index : array_search($argument->name->toString(), $names, true);
            if ($position === false) $position = $index;
            while (count($ordered) <= $position) $ordered[] = new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('null')));
            $ordered[$position] = clone $argument; $ordered[$position]->name = null;
        }
        $result = clone $node; $result->args = $ordered; return $result;
    }

    private function literalCount(Node $node): ?int
    {
        if (!$node instanceof Node\Expr\Array_) return null;
        foreach ($node->items as $item) if ($item === null || $item->unpack || $item->key !== null) return null;
        return count($node->items);
    }

    private function variables(mixed $value, array &$variables): void
    {
        if ($value instanceof Node\Expr\Variable && is_string($value->name)) $variables[] = $value->name;
        if ($value instanceof Node) foreach ($value->getSubNodeNames() as $key) $this->variables($value->$key, $variables);
        elseif (is_array($value)) foreach ($value as $node) $this->variables($node, $variables);
    }

    private function invalidateTarget(?Node $node, array &$env): void
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) unset($env[$node->name], $env["\0values"][$node->name]);
        elseif ($node instanceof Node\Expr\ArrayDimFetch) $this->invalidateTarget($node->var, $env);
    }

    private function invalidateAssignments(mixed $value, array &$env): void
    {
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) return;
        if ($value instanceof Node\Expr\Assign || $value instanceof Node\Expr\AssignOp) $this->invalidateTarget($value->var, $env);
        if ($value instanceof Node\Stmt\Foreach_) { $this->invalidateTarget($value->valueVar, $env); $this->invalidateTarget($value->keyVar, $env); }
        if ($value instanceof Node\Stmt\Catch_) $this->invalidateTarget($value->var, $env);
        if ($value instanceof Node) foreach ($value->getSubNodeNames() as $key) $this->invalidateAssignments($value->$key, $env);
        elseif (is_array($value)) foreach ($value as $node) $this->invalidateAssignments($node, $env);
    }

    private function hasYield(Node $node): bool
    {
        $visit = function (mixed $value) use (&$visit): bool {
            if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction || $value instanceof Node\Stmt\Function_ || $value instanceof Node\Stmt\Class_) return false;
            if ($value instanceof Node\Expr\Yield_ || $value instanceof Node\Expr\YieldFrom) return true;
            if ($value instanceof Node) foreach ($value->getSubNodeNames() as $key) { if ($visit($value->$key)) return true; }
            elseif (is_array($value)) foreach ($value as $node) { if ($visit($node)) return true; }
            return false;
        };
        return $visit($node->stmts ?? $node->expr ?? null);
    }

    private function isScalarBuiltin(Node\Expr\FuncCall $call, string $class, string $file, array $env, array $evaluated): bool
    {
        if (!$call->name instanceof Node\Name || !in_array(strtolower($call->name->toString()), ['mb_trim', 'max', 'min', 'trim', 'strlen', 'strtolower', 'strtoupper', 'intval', 'floatval', 'boolval', 'strval'], true)) return false;
        if (!$call->name instanceof Node\Name\FullyQualified) {
            if (!$call->name->isUnqualified() || $class === '') return false;
            $namespaceEnd = strrpos($class, '\\');
            $resolved = $call->name->getAttribute('namespacedName');
            $expected = $namespaceEnd === false ? $call->name->toString() : substr($class, 0, $namespaceEnd) . '\\' . $call->name->toString();
            if (!$resolved instanceof Node\Name || strcasecmp(Program::name($resolved), $expected) !== 0) return false;
            if ($namespaceEnd !== false) {
                // An application namespace can override the global builtin.
                // This opt-in is an operator assertion about code outside the
                // complete source snapshot, never an inference from imports.
                if (!$this->assumeGlobalScalarBuiltins || !in_array(strtolower($call->name->toString()), ['mb_trim', 'max', 'min'], true)) return false;
            }
        }
        foreach ($call->args as $index => $argument) if (!$argument instanceof Node\Arg || $argument->unpack || !($evaluated[$index]['scalar'] ?? false)) return false;
        return true;
    }

    private function isPureArrayBuiltin(Node\Expr\FuncCall $call, string $class): bool
    {
        if (!$call->name instanceof Node\Name || !in_array(strtolower($call->name->toString()), ['array_merge', 'array_keys', 'array_values', 'array_reverse', 'array_slice'], true)) return false;
        if (!$call->name instanceof Node\Name\FullyQualified) {
            if (!$call->name->isUnqualified() || str_contains($class, '\\')) return false;
            $resolved = $call->name->getAttribute('namespacedName');
            if (!$resolved instanceof Node\Name || strcasecmp(Program::name($resolved), $call->name->toString()) !== 0) return false;
        }
        // These array-only helpers do not invoke callbacks or object methods.
        // Arguments and source-visible overrides are evaluated first.
        foreach ($call->args as $argument) if (!$argument instanceof Node\Arg || $argument->unpack) return false;
        return true;
    }

    /** @param array<string, string> $env */
    private function receiver(Node\Expr $expr, string $class, string $file, array $env): ?string
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) return $env[$expr->name] ?? null;
        if ($expr instanceof Node\Expr\PropertyFetch && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier) {
            $owner = $env['this'] ?? '';
            for ($depth = 0; $depth < 48 && $owner !== null; ++$depth) {
                if (isset($this->program->properties[$owner][$expr->name->toString()])) return $this->program->properties[$owner][$expr->name->toString()];
                $owner = $this->program->parentClass($owner);
            }
            return null;
        }
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) return Program::name($expr->class);
        if (($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) && !$expr->isFirstClassCallable() && $expr->name instanceof Node\Identifier) {
            $base = $expr instanceof Node\Expr\StaticCall ? $this->className($expr->class, $class) : $this->receiver($expr->var, $class, $file, $env);
            if ($base !== null) {
                $method = strtolower($expr->name->toString());
                $model = $this->program->method($base, $method);
                if ($model !== null && $model['node']->returnType instanceof Node\Name) {
                    $type = Program::name($model['node']->returnType);
                    return match (strtolower($type)) { 'self' => $model['class'], 'static' => $base, 'parent' => $this->program->parentClass($model['class']), default => $type };
                }
                $api = $this->program->apiType($base);
                if ($model !== null && $api !== null && strcasecmp($base, $api) !== 0) $api = null;
                $type = match (true) {
                    $api === 'Doctrine\\DBAL\\Connection' && $method === 'prepare' => 'Doctrine\\DBAL\\Statement',
                    in_array($api, ['Doctrine\\DBAL\\Connection', 'Doctrine\\DBAL\\Statement', 'Doctrine\\DBAL\\Query\\QueryBuilder'], true) && $method === 'executequery' => 'Doctrine\\DBAL\\Result',
                    $api === 'Doctrine\\DBAL\\Connection' && $method === 'createquerybuilder' => 'Doctrine\\DBAL\\Query\\QueryBuilder',
                    $api === 'Doctrine\\ORM\\QueryBuilder' && $method === 'getquery' => 'Doctrine\\ORM\\Query',
                    in_array($api, ['Doctrine\\ORM\\EntityManagerInterface', 'Doctrine\\ORM\\EntityManager'], true) && $method === 'createquerybuilder' => 'Doctrine\\ORM\\QueryBuilder',
                    in_array($api, ['Doctrine\\ORM\\EntityManagerInterface', 'Doctrine\\ORM\\EntityManager'], true) && $method === 'createquery' => 'Doctrine\\ORM\\Query',
                    in_array($api, ['Doctrine\\ORM\\EntityManagerInterface', 'Doctrine\\ORM\\EntityManager'], true) && $method === 'createnativequery' => 'Doctrine\\ORM\\NativeQuery',
                    in_array($api, ['Doctrine\\DBAL\\Query\\QueryBuilder', 'Doctrine\\ORM\\QueryBuilder'], true) && in_array($method, ['select', 'addselect', 'from', 'where', 'andwhere', 'orwhere', 'setparameter', 'setmaxresults'], true) => $api,
                    default => null,
                };
                if ($type !== null) return $type;
            }
        }
        $analysis = $this->program->analysis->getFile($file);
        if ($analysis !== null && $expr->getStartFilePos() >= 0 && $expr->getEndFilePos() >= $expr->getStartFilePos()) {
            $type = $analysis->getExpressionType(new Span($expr->getStartFilePos(), $expr->getEndFilePos() + 1));
            if ($type !== null && count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof NamedObjectType) return $type->atomicTypes[0]->name;
        }
        return null;
    }

    /** @param list<string> $stack */
    private function call(string $receiver, string $method, string $class, string $file, array $stack, array $arguments = []): Estimate
    {
        if (strcasecmp($receiver, 'Closure') === 0 && in_array(strtolower($method), ['fromcallable', 'bind'], true)) return new Estimate();
        $api = $this->program->apiType($receiver);
        if ($api !== null && strcasecmp($api, $receiver) !== 0 && $this->program->method($receiver, $method) !== null) return $this->method($receiver, $method, $stack, $arguments);
        $receiver = $api ?? $receiver;
        $name = strtolower($method);
        if ($receiver === 'Doctrine\\DBAL\\Connection' && $name === 'transactional' || in_array($receiver, ['Doctrine\\ORM\\EntityManager', 'Doctrine\\ORM\\EntityManagerInterface'], true) && $name === 'wrapintransaction') {
            ++$this->uncertainCallback;
            try { $effect = $this->invoke($this->argument($arguments, 0, 'func')['callable'] ?? null, [['object' => $receiver]], $stack, $file); }
            finally { --$this->uncertainCallback; }
            return (new Estimate(0, $effect->upper, $effect->unknown, $effect->cycles))->plus(Estimate::unknown($name === 'transactional' ? 'Doctrine transaction control and callback invocation bound are not proven' : 'ORM transaction, flush and UnitOfWork effects are not bounded'));
        }
        if (in_array($receiver, ['Doctrine\\ORM\\EntityManager', 'Doctrine\\ORM\\EntityManagerInterface'], true) && in_array($name, ['flush', 'persist', 'remove'], true)) return Estimate::unknown('ORM UnitOfWork, lifecycle events and lazy loading are not bounded');

        if (in_array($receiver, ['Symfony\\Contracts\\Cache\\CacheInterface', 'Symfony\\Contracts\\Cache\\TagAwareCacheInterface'], true) && $name === 'get') {
            ++$this->uncertainCallback;
            try { $effect = $this->invoke($this->argument($arguments, 1, 'callback')['callable'] ?? null, [], $stack, $file); }
            finally { --$this->uncertainCallback; }
            return (new Estimate(0, $effect->upper, $effect->unknown, $effect->cycles))->plus(Estimate::unknown('Symfony cache backend and callback invocation bound are not proven'));
        }
        if ($receiver === 'Doctrine\\DBAL\\Connection' && in_array($name, ['executequery', 'executestatement', 'fetchallassociative', 'fetchallnumeric', 'fetchallkeyvalue', 'fetchallassociativeindexed', 'fetchfirstcolumn', 'fetchone', 'fetchassociative', 'fetchvalue', 'insert', 'update', 'delete'], true)) return new Estimate(1, 1);
        if ($receiver === 'Doctrine\\DBAL\\Statement' && in_array($name, ['execute', 'executequery', 'executestatement'], true)) return new Estimate(1, 1);
        if ($receiver === 'Doctrine\\DBAL\\Result' && in_array($name, ['fetchone', 'fetchassociative', 'fetchallassociative', 'fetchallnumeric', 'fetchallkeyvalue', 'fetchallassociativeindexed', 'fetchfirstcolumn', 'rowcount', 'free'], true)) return new Estimate();
        if ($receiver === 'Doctrine\\DBAL\\Query\\QueryBuilder' && in_array($name, ['executequery', 'executestatement'], true)) return new Estimate(1, 1);
        if (in_array($receiver, ['Doctrine\\ORM\\Query', 'Doctrine\\ORM\\AbstractQuery', 'Doctrine\\ORM\\NativeQuery'], true) && in_array($name, ['execute', 'getresult', 'getsingleresult', 'getoneornullresult', 'getscalarresult', 'getsinglescalarresult', 'getarrayresult', 'toiterable'], true)) return new Estimate(0, null, ['ORM cache, hydration and lazy loading are not bounded']);
        if (in_array($receiver, ['Doctrine\\ORM\\EntityRepository', 'Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository'], true) && in_array($name, ['find', 'findall', 'findby', 'findoneby', 'count'], true)) return new Estimate(0, null, ['repository/cache/lazy-loading behavior']);
        if ($this->program->method($receiver, $method) !== null) return $this->method($receiver, $method, $stack, $arguments);
        if ($this->isProvenConstruction($receiver, $name)) return new Estimate();
        return Estimate::unknown('unresolved call ' . $receiver . '::' . $method . ' at ' . $file);
    }

    private function isProvenConstruction(string $receiver, string $method): bool
    {
        return match ($receiver) {
            'Doctrine\\DBAL\\Connection' => in_array($method, ['prepare', 'createquerybuilder'], true),
            'Doctrine\\DBAL\\Query\\QueryBuilder', 'Doctrine\\ORM\\QueryBuilder' => in_array($method, ['select', 'addselect', 'from', 'where', 'andwhere', 'orwhere', 'setparameter', 'setmaxresults', 'getquery', 'getsql', 'getdql'], true),
            'Doctrine\\ORM\\EntityManagerInterface', 'Doctrine\\ORM\\EntityManager' => in_array($method, ['createquery', 'createquerybuilder', 'createnativequery'], true),
            default => false,
        };
    }

    /** Only calls evaluated in this body can disprove its recursion breaker. */
    private function executedCalls(array $statements, string $type): array
    {
        $calls = [];
        $visit = function (mixed $value) use (&$visit, &$calls, $type): void {
            if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction || $value instanceof Node\Stmt\Class_ || $value instanceof Node\Stmt\Function_) return;
            if ($value instanceof $type && !$value->isFirstClassCallable()) $calls[] = $value;
            if ($value instanceof Node) foreach ($value->getSubNodeNames() as $name) $visit($value->$name);
            elseif (is_array($value)) foreach ($value as $node) $visit($node);
        };
        $visit($statements);
        return $calls;
    }

    private function hasProvenBreaker(Node\Stmt\ClassMethod $method, string $name): bool
    {
        $parameter = $method->params[0] ?? null;
        $guard = $method->stmts[0] ?? null;
        if (!$parameter?->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || !$guard instanceof Node\Stmt\If_) return false;
        $variable = $parameter->var->name;
        $condition = $guard->cond;
        if (!$condition instanceof Node\Expr\BinaryOp\SmallerOrEqual || !$condition->left instanceof Node\Expr\Variable || $condition->left->name !== $variable || !$condition->right instanceof Node\Scalar\Int_ || $condition->right->value !== 0 || !($guard->stmts[0] ?? null) instanceof Node\Stmt\Return_) return false;
        $found = false;
        foreach ($this->executedCalls($method->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
            if ($call->isFirstClassCallable()) continue;
            if (!$call->name instanceof Node\Identifier || strcasecmp($call->name->toString(), $name) !== 0) continue;
            if (!$call->var instanceof Node\Expr\Variable || $call->var->name !== 'this') return false;
            $arg = ($call->args[0] ?? null) instanceof Node\Arg ? $call->args[0]->value : null;
            if (!$arg instanceof Node\Expr\BinaryOp\Minus || !$arg->left instanceof Node\Expr\Variable || $arg->left->name !== $variable || !$arg->right instanceof Node\Scalar\Int_ || $arg->right->value < 1) return false;
            $found = true;
        }
        return $found;
    }

    private function hasProvenFunctionBreaker(Node\Stmt\Function_ $function, string $name): bool
    {
        $parameter = $function->params[0] ?? null;
        $guard = $function->stmts[0] ?? null;
        if (!$parameter?->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || !$guard instanceof Node\Stmt\If_) return false;
        $variable = $parameter->var->name;
        $condition = $guard->cond;
        if (!$condition instanceof Node\Expr\BinaryOp\SmallerOrEqual || !$condition->left instanceof Node\Expr\Variable || $condition->left->name !== $variable || !$condition->right instanceof Node\Scalar\Int_ || $condition->right->value !== 0 || !($guard->stmts[0] ?? null) instanceof Node\Stmt\Return_) return false;
        $found = false;
        foreach ($this->executedCalls($function->stmts, Node\Expr\FuncCall::class) as $call) {
            if ($call->isFirstClassCallable()) continue;
            if (!$call->name instanceof Node\Name) continue;
            $namespaced = $call->name->getAttribute('namespacedName');
            $calledName = Program::name($namespaced instanceof Node\Name ? $namespaced : $call->name);
            if (strcasecmp($calledName, $name) !== 0) continue;
            $arg = ($call->args[0] ?? null) instanceof Node\Arg ? $call->args[0]->value : null;
            if (!$arg instanceof Node\Expr\BinaryOp\Minus || !$arg->left instanceof Node\Expr\Variable || $arg->left->name !== $variable || !$arg->right instanceof Node\Scalar\Int_ || $arg->right->value < 1) return false;
            $found = true;
        }
        return $found;
    }
}
