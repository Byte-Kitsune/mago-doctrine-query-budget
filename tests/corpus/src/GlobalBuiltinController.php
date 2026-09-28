<?php

declare(strict_types=1);

final class GlobalBuiltinController
{
    public function index(): void
    {
        max(1, 2);
    }
}
