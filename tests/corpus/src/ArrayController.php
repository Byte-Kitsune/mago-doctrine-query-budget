<?php

declare(strict_types=1);

namespace App;

final class ArrayController
{
    public function index(): array
    {
        return \array_values(\array_merge(['one'], ['two']));
    }
}
