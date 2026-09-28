<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Conservative control-flow and call summary over one Mago source generation. */
final class Evaluator
{
    private int $visits = 0;

    public function __construct(private readonly Program $program) {}

    /** @param list<string> $stack */
    public function method(string $class, string $name, array $stack = []): Estimate
    {
        if (++$this->visits > 25_000) return Estimate::unknown('analysis work limit');
        $key = Program::key($class, $name);
        $model = $this->program->method($class, $name);
        if ($model === null) return Estimate::unknown('unresolved method ' . $class . '::' . $name);
        if (in_array($key, $stack, true)) {
            if (end($stack) === $key && $this->hasProvenBreaker($model['node'], $name)) {
                return Estimate::unknown('guarded recursion has unknown invocation bound: ' . $key);
            }
            return new Estimate(0, null, ['unbounded recursion: ' . $key], [$key]);
        }
        if (count($stack) >= 48) return Estimate::unknown('call depth limit');
        $env = ['this' => $model['class']];
        foreach ($model['node']->params as $parameter) {
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || !$parameter->type instanceof Node\Name) continue;
            $env[$parameter->var->name] = Program::name($parameter->type);
        }
        return $this->statements($model['node']->stmts ?? [], $model['class'], $model['file'], $env, [...$stack, $key]);
    }

    /** @param list<string> $stack */
    public function functionCall(string $name, array $stack = []): Estimate
    {
        if (++$this->visits > 25_000) return Estimate::unknown('analysis work limit');
        $model = $this->program->functionModel($name);
        if ($model === null) return Estimate::unknown('unresolved function ' . $name);
        $key = 'function:' . strtolower($model['name']);
        if (in_array($key, $stack, true)) {
            if (end($stack) === $key && $this->hasProvenFunctionBreaker($model['node'], $model['name'])) {
                return Estimate::unknown('guarded recursion has unknown invocation bound: ' . $key);
            }
            return new Estimate(0, null, ['unbounded recursion: ' . $key], [$key]);
        }
        if (count($stack) >= 48) return Estimate::unknown('call depth limit');
        $env = [];
        foreach ($model['node']->params as $parameter) {
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || !$parameter->type instanceof Node\Name) continue;
            $env[$parameter->var->name] = Program::name($parameter->type);
        }
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
                if ($this->containsDirectExit($statement->stmts) || $statement->else !== null && $this->containsDirectExit($statement->else->stmts)) {
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
                        unset($catchEnv[$catch->var->name]);
                    }
                    $catches = $catches->branch($this->statements($catch->stmts, $class, $file, $catchEnv, $stack));
                }
                $body = $try->plus($catches);
                $sum = $sum->plus(new Estimate(0, $body->upper, $body->unknown, $body->cycles));
                if ($statement->finally !== null) {
                    $sum = $sum->plus($this->statements($statement->finally->stmts, $class, $file, $env, $stack));
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\Foreach_) {
                $sum = $sum->plus($this->expression($statement->expr, $class, $file, $env, $stack));
                $body = $this->statements($statement->stmts, $class, $file, $env, $stack);
                if ($statement->expr instanceof Node\Expr\Array_) {
                    $sum = $sum->plus($body->times(count($statement->expr->items)));
                } elseif ($body->upper !== 0 || $body->unknown !== []) {
                    $sum = $sum->plus(Estimate::unknown('unbounded foreach at ' . $file . ':' . $statement->getStartLine()));
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\While_ || $statement instanceof Node\Stmt\Do_ || $statement instanceof Node\Stmt\For_) {
                $sum = $sum->plus(Estimate::unknown('unbounded loop at ' . $file . ':' . $statement->getStartLine()));
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
            null,
            array_values(array_unique([...$sum->unknown, 'conditional early exit at ' . $file])),
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
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            $sum = $this->expression($expr->var, $class, $file, $env, $stack);
            foreach ($expr->args as $arg) if ($arg instanceof Node\Arg) $sum = $sum->plus($this->expression($arg->value, $class, $file, $env, $stack));
            if (!$expr->name instanceof Node\Identifier) return $sum->plus(Estimate::unknown('dynamic method call at ' . $file . ':' . $expr->getStartLine()));
            $receiver = $this->receiver($expr->var, $class, $file, $env);
            if ($receiver === null) return $sum->plus(Estimate::unknown('unknown receiver for ' . $expr->name . ' at ' . $file . ':' . $expr->getStartLine()));
            $effect = $this->call($receiver, $expr->name->toString(), $class, $file, $stack);
            return $sum->plus($expr instanceof Node\Expr\NullsafeMethodCall ? $effect->branch(new Estimate()) : $effect);
        }
        if ($expr instanceof Node\Expr\StaticCall) {
            $sum = new Estimate();
            foreach ($expr->args as $arg) if ($arg instanceof Node\Arg) $sum = $sum->plus($this->expression($arg->value, $class, $file, $env, $stack));
            if ($expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
                return $sum->plus($this->call(Program::name($expr->class), $expr->name->toString(), $class, $file, $stack));
            }
            return $sum->plus(Estimate::unknown('dynamic static call at ' . $file . ':' . $expr->getStartLine()));
        }
        if ($expr instanceof Node\Expr\Assign) {
            $sum = $this->expression($expr->expr, $class, $file, $env, $stack);
            if ($expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                $type = $this->receiver($expr->expr, $class, $file, $env);
                if ($type !== null) $env[$expr->var->name] = $type;
            }
            return $sum;
        }
        if ($expr instanceof Node\Expr\Ternary) {
            $condition = $this->expression($expr->cond, $class, $file, $env, $stack);
            $yes = $expr->if === null ? new Estimate() : $this->expression($expr->if, $class, $file, $env, $stack);
            return $condition->plus($yes->branch($this->expression($expr->else, $class, $file, $env, $stack)));
        }
        if ($expr instanceof Node\Expr\New_) {
            $sum = new Estimate();
            foreach ($expr->args as $arg) if ($arg instanceof Node\Arg) $sum = $sum->plus($this->expression($arg->value, $class, $file, $env, $stack));
            if ($expr->class instanceof Node\Name && $this->program->method(Program::name($expr->class), '__construct') !== null) {
                return $sum->plus($this->method(Program::name($expr->class), '__construct', $stack));
            }
            return $sum;
        }
        if ($expr instanceof Node\Expr\FuncCall) {
            $sum = new Estimate();
            foreach ($expr->args as $arg) if ($arg instanceof Node\Arg) $sum = $sum->plus($this->expression($arg->value, $class, $file, $env, $stack));
            if (!$expr->name instanceof Node\Name) return $sum->plus(Estimate::unknown('dynamic function call at ' . $file . ':' . $expr->getStartLine()));
            $namespaced = $expr->name->getAttribute('namespacedName');
            $candidates = $namespaced instanceof Node\Name
                ? [Program::name($namespaced), $expr->name->toString()]
                : [Program::name($expr->name)];
            foreach ($candidates as $candidate) {
                if (isset($this->program->ambiguousFunctions[strtolower($candidate)])) {
                    return $sum->plus(Estimate::unknown('ambiguous function call ' . $candidate . ' at ' . $file . ':' . $expr->getStartLine()));
                }
                if ($this->program->functionModel($candidate) !== null) return $sum->plus($this->functionCall($candidate, $stack));
            }
            if ($this->isScalarBuiltin($expr, $file)) return $sum;
            $name = implode(' or ', $candidates);
            return $sum->plus(Estimate::unknown('unresolved function call ' . $name . ' at ' . $file . ':' . $expr->getStartLine()));
        }
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

    private function isScalarBuiltin(Node\Expr\FuncCall $call, string $file): bool
    {
        // A namespaced function may override a builtin outside this source
        // snapshot. Only an explicit global call has stable PHP semantics.
        if (!$call->name instanceof Node\Name\FullyQualified || !in_array(strtolower($call->name->toString()), ['mb_trim', 'max', 'min'], true)) return false;
        $analysis = $this->program->analysis->getFile($file);
        foreach ($call->args as $argument) {
            if (!$argument instanceof Node\Arg || $argument->unpack) return false;
            $value = $argument->value;
            if ($value instanceof Node\Scalar\String_ || $value instanceof Node\Scalar\Int_ || $value instanceof Node\Scalar\Float_) continue;
            if (!$value instanceof Node\Expr\Variable || !is_string($value->name) || $analysis === null) return false;
            $type = $analysis->getExpressionType(new Span($value->getStartFilePos(), $value->getEndFilePos() + 1));
            if ($type === null) return false;
            foreach ($type->atomicTypes as $atomic) {
                if (!$atomic instanceof ScalarType || !in_array($atomic->kind, [ScalarTypeKind::Boolean, ScalarTypeKind::Integer, ScalarTypeKind::Float, ScalarTypeKind::String], true)) return false;
            }
        }
        return true;
    }

    /** @param array<string, string> $env */
    private function receiver(Node\Expr $expr, string $class, string $file, array $env): ?string
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) return $env[$expr->name] ?? null;
        if ($expr instanceof Node\Expr\PropertyFetch && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier) {
            return $this->program->properties[$class][$expr->name->toString()] ?? null;
        }
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) return Program::name($expr->class);
        $analysis = $this->program->analysis->getFile($file);
        if ($analysis !== null && $expr->getStartFilePos() >= 0 && $expr->getEndFilePos() >= $expr->getStartFilePos()) {
            $type = $analysis->getExpressionType(new Span($expr->getStartFilePos(), $expr->getEndFilePos() + 1));
            if ($type !== null && count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof NamedObjectType) {
                return $type->atomicTypes[0]->name;
            }
        }
        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $base = $this->receiver($expr->var, $class, $file, $env);
            $name = strtolower($expr->name->toString());
            if ($base === 'Doctrine\\ORM\\QueryBuilder' && $name === 'getquery') return 'Doctrine\\ORM\\Query';
            if ($base === 'Doctrine\\DBAL\\Connection' && $name === 'createquerybuilder') return 'Doctrine\\DBAL\\Query\\QueryBuilder';
        }
        return null;
    }

    /** @param list<string> $stack */
    private function call(string $receiver, string $method, string $class, string $file, array $stack): Estimate
    {
        $name = strtolower($method);
        if ($receiver === 'Doctrine\\DBAL\\Connection' && in_array($name, ['executequery', 'executestatement', 'fetchallassociative', 'fetchallnumeric', 'fetchfirstcolumn', 'fetchone', 'fetchassociative', 'fetchvalue', 'insert', 'update', 'delete'], true)) return new Estimate(1, 1);
        if ($receiver === 'Doctrine\\DBAL\\Statement' && $name === 'execute') return new Estimate(1, 1);
        if ($receiver === 'Doctrine\\DBAL\\Result' && in_array($name, ['fetchone', 'fetchassociative', 'fetchallassociative', 'fetchallnumeric', 'fetchfirstcolumn', 'rowcount', 'free'], true)) return new Estimate();
        if ($receiver === 'Doctrine\\DBAL\\Query\\QueryBuilder' && in_array($name, ['executequery', 'executestatement'], true)) return new Estimate(1, 1);
        if ($receiver === 'Doctrine\\ORM\\Query' && in_array($name, ['execute', 'getresult', 'getsingleresult', 'getoneornullresult', 'getscalarresult', 'getarrayresult', 'toiterable'], true)) return new Estimate(0, null, ['ORM cache, hydration and lazy loading are not bounded']);
        if (in_array($receiver, ['Doctrine\\ORM\\EntityRepository', 'Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository'], true) && in_array($name, ['find', 'findall', 'findby', 'findoneby', 'count'], true)) return new Estimate(0, null, ['repository/cache/lazy-loading behavior']);
        if ($this->program->method($receiver, $method) !== null) return $this->method($receiver, $method, $stack);
        if ($this->isProvenConstruction($receiver, $name)) return new Estimate();
        return Estimate::unknown('unresolved call ' . $receiver . '::' . $method . ' at ' . $file);
    }

    private function isProvenConstruction(string $receiver, string $method): bool
    {
        return match ($receiver) {
            'Doctrine\\DBAL\\Connection' => in_array($method, ['prepare', 'createquerybuilder'], true),
            'Doctrine\\DBAL\\Query\\QueryBuilder', 'Doctrine\\ORM\\QueryBuilder' => in_array($method, ['select', 'addselect', 'from', 'where', 'andwhere', 'orwhere', 'setparameter', 'setmaxresults', 'getquery', 'getsql', 'getdql'], true),
            'Doctrine\\ORM\\EntityManagerInterface', 'Doctrine\\ORM\\EntityManager' => in_array($method, ['createquery', 'createquerybuilder'], true),
            default => false,
        };
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
        foreach ((new NodeFinder())->findInstanceOf($method->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
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
        foreach ((new NodeFinder())->findInstanceOf($function->stmts, Node\Expr\FuncCall::class) as $call) {
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
