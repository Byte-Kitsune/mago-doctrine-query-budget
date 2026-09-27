<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;

interface ReportServiceInterface
{
    public function load(): void;
}

final class ReportService implements ReportServiceInterface
{
    public function __construct(private Connection $connection) {}

    public function load(): void
    {
        $this->connection->executeQuery('SELECT id FROM reports');
        $this->connection->executeStatement('UPDATE reports SET viewed = 1');
    }
}
