<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

use ByteKitsune\MagoNative\Engine;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Span;

/** Protocol adapter. Conservative query evaluation executes in Rust. */
final class NativeBudget
{
    private static ?Program $loaded = null;

    public static function estimate(Program $program, string $class, string $method, bool $assumeScalar, ?string $function = null): Estimate
    {
        if (!$program->native && self::$loaded !== $program) {
            Engine::initializeBudget([
                'properties' => (object) array_map(static fn (array $values): object => (object) $values, $program->properties),
                'parents' => (object) $program->parents,
                'interfaces' => (object) $program->interfaces,
                'constants' => (object) $program->constants,
                'property_hooks' => (object) $program->propertyHooks,
                'trait_uses' => (object) $program->traitUses,
                'trait_definitions' => (object) $program->traitDefinitions,
                'adapted_traits' => (object) $program->adaptedTraitUses,
                'ambiguous_functions' => (object) $program->ambiguousFunctions,
            ], $program->methods, $program->traitMethods, $program->functions);
            self::$loaded = $program;
        }
        $types = [];
        $callback = static function (array $query) use ($program, &$types): ?array {
            $file = $query['file']; $start = $query['start']; $end = $query['end'];
            if (!is_string($file) || !is_int($start) || !is_int($end) || $start < 0 || $end < $start) throw new \RuntimeException('Invalid native metadata request.');
            $key = $file . ':' . $start . ':' . $end;
            if (array_key_exists($key, $types)) return $types[$key];
            $analysis = $program->analysis->getFile($file);
            $type = $analysis?->getExpressionType(new Span($start, $end));
            if ($type === null) return $types[$key] = null;
            $object = count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof NamedObjectType ? $type->atomicTypes[0]->name : null;
            $scalar = true;
            foreach ($type->atomicTypes as $atomic) {
                if (!$atomic instanceof ScalarType || !in_array($atomic->kind, [ScalarTypeKind::Boolean, ScalarTypeKind::Integer, ScalarTypeKind::Float, ScalarTypeKind::String], true)) $scalar = false;
            }
            return $types[$key] = ['object' => $object, 'scalar' => $scalar];
        };
        $estimate = Engine::budget($class, $method, $assumeScalar, $callback, $function, $program->nativeSession);
        return new Estimate($estimate['lower'], $estimate['upper'], $estimate['unknown'], $estimate['cycles']);
    }
}
