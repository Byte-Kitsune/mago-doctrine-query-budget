<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;

interface ServiceInterface { public function run(): void; }

final class Service implements ServiceInterface
{
    public function __construct(private Connection $connection) {}

    public function run(): void
    {
        $this->connection->executeQuery('SELECT 1');
        $this->connection->executeStatement('UPDATE things SET done = 1');
        $this->connection->prepare('SELECT 2');
    }
}
