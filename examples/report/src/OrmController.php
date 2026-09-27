<?php

declare(strict_types=1);

namespace App;

use Doctrine\ORM\Query;

final class OrmController
{
    public function __construct(private Query $query) {}

    public function index(): void
    {
        $this->query->getResult();
    }
}
