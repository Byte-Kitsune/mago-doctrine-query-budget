<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;

final class InspectionService
{
    public function __construct(private Connection $connection, private Service $service) {}

    private function internal(): void
    {
        $this->connection->executeQuery('SELECT 1');
    }

    public function run(): void
    {
        $this->internal();
        $this->service->run();
    }

    public function uncertain(\stdClass $dynamic): void
    {
        $dynamic->fetch();
    }
}

abstract class AbstractInspectionService
{
    abstract public function run(): void;
}
