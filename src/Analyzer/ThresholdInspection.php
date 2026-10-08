<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Reads configuration syntax without evaluating or loading that configuration. */
final class ThresholdInspection
{
    /** @return array{schemaVersion: string, status: string, warning?: int, error?: int, message?: string} */
    public static function source(string $source): array
    {
        if (strlen($source) > 1048576) return self::unresolved('The extension configuration exceeds the inspection size limit.');
        try {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
            $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
        } catch (\Throwable) {
            return self::unresolved('The extension configuration could not be parsed.');
        }
        if (count((new NodeFinder())->find($ast, static fn(Node $node): bool => true)) > 50000)
            return self::unresolved('The extension configuration exceeds the inspection work limit.');
        $reports = [];
        self::statements($ast, [], [], $reports);
        if ($reports === []) {
            if ((new NodeFinder())->findFirstInstanceOf($ast, Node\Expr\Include_::class) !== null)
                return self::unresolved('Included configuration cannot be resolved without executing external PHP.');
            return ['schemaVersion' => '1', 'status' => 'absent'];
        }
        $pair = null;
        foreach ($reports as $report) {
            if ($report['status'] !== 'resolved') return $report;
            $current = [$report['warning'], $report['error']];
            if ($pair !== null && $pair !== $current)
                return self::unresolved('Multiple extension configurations declare different query thresholds.');
            $pair = $current;
        }
        return ['schemaVersion' => '1', 'status' => 'resolved', 'warning' => $pair[0], 'error' => $pair[1]];
    }

    /** Literal assignments are trusted only in their sequential statement scope. Calls and control flow discard them. */
    private static function statements(array $statements, array $variables, array $constants, array &$reports): void
    {
        $assigned = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                self::statements($statement->stmts, [], [], $reports);
                $variables = [];
                continue;
            }
            if ($statement instanceof Node\Stmt\Const_) {
                foreach ($statement->consts as $constant) {
                    $name = ($constant->namespacedName ?? $constant->name)->toString();
                    $value = self::integer($constant->value, [], $constants);
                    $constants[$name] = array_key_exists($name, $constants) ? null : $value;
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign
                && $statement->expr->var instanceof Node\Expr\Variable && is_string($statement->expr->var->name)) {
                $name = $statement->expr->var->name;
                $value = self::integer($statement->expr->expr, $variables, $constants);
                $variables[$name] = isset($assigned[$name]) ? null : $value;
                $assigned[$name] = true;
                self::walk($statement->expr->expr, [], $constants, $reports);
                continue;
            }
            self::walk($statement, $variables, $constants, $reports);
            // Arbitrary calls, branches or included files could mutate local variables by reference.
            $variables = [];
        }
    }

    private static function walk(Node $node, array $variables, array $constants, array &$reports): void
    {
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
            && strcasecmp(ltrim($node->class->toString(), '\\'), QueryBudgetExtension::class) === 0
            && $node->name instanceof Node\Identifier && strcasecmp($node->name->toString(), 'create') === 0) {
            if (count($reports) >= 128) {
                if (count($reports) === 128) $reports[] = self::unresolved('Too many extension configurations were found.');
                return;
            }
            $reports[] = self::call($node, $variables, $constants);
            return;
        }
        $conditional = ($node instanceof Node\Stmt && !($node instanceof Node\Stmt\Return_) && !($node instanceof Node\Stmt\Expression))
            || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Expr\Ternary || $node instanceof Node\Expr\Match_
            || $node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\BooleanOr
            || $node instanceof Node\Expr\BinaryOp\LogicalAnd || $node instanceof Node\Expr\BinaryOp\LogicalOr
            || $node instanceof Node\Expr\BinaryOp\Coalesce;
        if ($conditional) {
            $calls = (new NodeFinder())->find($node, static fn(Node $child): bool => $child instanceof Node\Expr\StaticCall
                && $child->class instanceof Node\Name && strcasecmp(ltrim($child->class->toString(), '\\'), QueryBudgetExtension::class) === 0
                && $child->name instanceof Node\Identifier && strcasecmp($child->name->toString(), 'create') === 0);
            if ($calls !== []) $reports[] = self::unresolved('A conditional or deferred extension configuration cannot prove active query thresholds.');
            return;
        }
        // A nested branch is not a sequential proof of a variable's value.
        $nested = !($node instanceof Node\Stmt\Return_) && !($node instanceof Node\Stmt\Expression);
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;
            if ($child instanceof Node) self::walk($child, $nested ? [] : $variables, $constants, $reports);
            elseif (is_array($child)) foreach ($child as $item)
                if ($item instanceof Node) self::walk($item, $nested ? [] : $variables, $constants, $reports);
        }
    }

    private static function call(Node\Expr\StaticCall $call, array $variables, array $constants): array
    {
        $parameters = (new \ReflectionMethod(QueryBudgetExtension::class, 'create'))->getParameters();
        $values = ['warningThreshold' => $parameters[2]->getDefaultValue(), 'errorThreshold' => $parameters[3]->getDefaultValue()];
        $seen = [];
        foreach ($call->args as $index => $argument) {
            if (!$argument instanceof Node\Arg || $argument->unpack)
                return self::unresolved('Unpacked extension arguments cannot be resolved statically.');
            $name = $argument->name?->toString() ?? (isset($parameters[$index]) ? $parameters[$index]->getName() : null);
            if ($name === null || !in_array($name, array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $parameters), true) || isset($seen[$name])) return self::unresolved('The extension arguments are ambiguous.');
            $seen[$name] = true;
            if (!array_key_exists($name, $values)) continue;
            $value = self::integer($argument->value, $variables, $constants);
            if ($value === null) return self::unresolved('Query thresholds contain dynamic or ambiguous expressions.');
            $values[$name] = $value;
        }
        if ($values['warningThreshold'] < 1 || $values['errorThreshold'] < $values['warningThreshold'])
            return self::unresolved('The extension query thresholds are invalid.');
        return ['schemaVersion' => '1', 'status' => 'resolved', 'warning' => $values['warningThreshold'], 'error' => $values['errorThreshold']];
    }

    private static function integer(Node\Expr $expression, array $variables, array $constants): ?int
    {
        if ($expression instanceof Node\Scalar\Int_) $value = $expression->value;
        elseif ($expression instanceof Node\Expr\Variable && is_string($expression->name)) $value = $variables[$expression->name] ?? null;
        elseif ($expression instanceof Node\Expr\ConstFetch) $value = $constants[ltrim(($expression->name->getAttribute('namespacedName') ?? $expression->name)->toString(), '\\')] ?? null;
        else return null;
        return is_int($value) && $value >= 0 && $value <= 9007199254740991 ? $value : null;
    }

    private static function unresolved(string $message): array
    {
        return ['schemaVersion' => '1', 'status' => 'unresolved', 'message' => $message];
    }
}
