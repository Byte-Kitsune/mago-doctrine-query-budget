<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;

final class ReportCommand
{
    public function __construct(private Connection $connection) {}

    protected function execute(): int
    {
        $this->connection->executeQuery('SELECT 1');
        return 0;
    }
}
