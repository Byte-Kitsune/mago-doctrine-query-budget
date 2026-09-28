<?php

declare(strict_types=1);

use function External\Query\max as max;

final class GlobalAliasController
{
    public function index(): void
    {
        max(1, 2);
    }
}
