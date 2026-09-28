<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;

function queryOnce(Connection $connection): void
{
    $connection->executeQuery('SELECT 1');
}

function guardedQuery(int $remaining, Connection $connection): void
{
    if ($remaining <= 0) return;
    $connection->executeQuery('SELECT 1');
    guardedQuery($remaining - 1, $connection);
}

function endlessQuery(): void
{
    endlessQuery();
}

final class FunctionController
{
    public function __construct(private Connection $connection) {}

    public function index(): void
    {
        queryOnce($this->connection);
        queryOnce($this->connection);
        queryOnce($this->connection);
        queryOnce($this->connection);
    }
}

final class UnknownFunctionController
{
    public function index(): void
    {
        \json_encode(new \stdClass());
    }
}

final class GuardedFunctionController
{
    public function __construct(private Connection $connection) {}

    public function index(): void
    {
        guardedQuery(2, $this->connection);
    }
}

final class CyclicFunctionController
{
    public function index(): void
    {
        endlessQuery();
    }
}
