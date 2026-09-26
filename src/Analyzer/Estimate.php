<?php

declare(strict_types=1);

namespace ByteKitsune\MagoDoctrineQueryBudget\Analyzer;

final class Estimate
{
    /** @param list<string> $unknown @param list<string> $cycles */
    public function __construct(
        public readonly int $lower = 0,
        public readonly ?int $upper = 0,
        public readonly array $unknown = [],
        public readonly array $cycles = [],
    ) {}

    public function plus(self $other): self
    {
        return new self(
            min(PHP_INT_MAX, $this->lower + $other->lower),
            $this->upper === null || $other->upper === null ? null : min(PHP_INT_MAX, $this->upper + $other->upper),
            array_values(array_unique([...$this->unknown, ...$other->unknown])),
            array_values(array_unique([...$this->cycles, ...$other->cycles])),
        );
    }

    public function branch(self $other): self
    {
        return new self(
            min($this->lower, $other->lower),
            $this->upper === null || $other->upper === null ? null : max($this->upper, $other->upper),
            array_values(array_unique([...$this->unknown, ...$other->unknown])),
            array_values(array_unique([...$this->cycles, ...$other->cycles])),
        );
    }

    public function times(int $count): self
    {
        return new self(
            min(PHP_INT_MAX, $this->lower * $count),
            $this->upper === null ? null : min(PHP_INT_MAX, $this->upper * $count),
            $this->unknown,
            $this->cycles,
        );
    }

    public static function unknown(string $reason): self
    {
        return new self(0, null, [$reason]);
    }
}
