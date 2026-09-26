<?php

declare(strict_types=1);

namespace Doctrine\ORM;

final class Query
{
    public function getResult(): array { return []; }
}

namespace App;

final class OrmController
{
    public function __construct(private \Doctrine\ORM\Query $query) {}

    public function index(): void { $this->query->getResult(); }
}
