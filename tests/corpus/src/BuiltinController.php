<?php

declare(strict_types=1);

namespace App;

final class BuiltinController
{
    public function index(string $term, int $limit): void
    {
        \mb_trim($term);
        \max($limit, 1);
        \min(2, 3);
    }
}

final class UnqualifiedBuiltinController
{
    public function index(): void
    {
        max(1, 2);
    }
}

final class ObjectBuiltinController
{
    public function index(SearchGateway $search): void
    {
        \max($search, $search);
    }
}
