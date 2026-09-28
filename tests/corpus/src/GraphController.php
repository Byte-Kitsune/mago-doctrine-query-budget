<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;

trait QueryTrait
{
    private function queryThroughTrait(Connection $connection): void
    {
        $connection->executeQuery('SELECT 1');
        $connection->executeQuery('SELECT 2');
        $connection->executeQuery('SELECT 3');
        $connection->executeQuery('SELECT 4');
    }
}

final class TraitService
{
    use QueryTrait;

    public function __construct(private Connection $connection) {}

    public function run(): void
    {
        $this->queryThroughTrait($this->connection);
    }
}

final class TraitController
{
    public function __construct(private TraitService $service) {}

    public function index(): void
    {
        $this->service->run();
    }
}

class BaseQueryService
{
    protected static function query(Connection $connection): void
    {
        $connection->executeQuery('SELECT 1');
        $connection->executeQuery('SELECT 2');
    }
}

final class ScopedQueryService extends BaseQueryService
{
    public function __construct(private Connection $connection) {}

    public function run(): void
    {
        parent::query($this->connection);
        self::query($this->connection);
    }
}

final class ScopedController
{
    public function __construct(private ScopedQueryService $service) {}

    public function index(): void
    {
        $this->service->run();
    }
}

final class AdaptedService
{
    use QueryTrait { queryThroughTrait as renamedQuery; }

    public function __construct(private Connection $connection) {}

    public function run(): void
    {
        $this->renamedQuery($this->connection);
    }
}

final class AdaptedController
{
    public function __construct(private AdaptedService $service) {}

    public function index(): void
    {
        $this->service->run();
    }
}

final class GuardController
{
    public function __construct(private Connection $connection) {}

    public function index(bool $skip): void
    {
        if ($skip) return;
        $this->connection->executeQuery('SELECT 1');
        $this->connection->executeQuery('SELECT 2');
        $this->connection->executeQuery('SELECT 3');
        $this->connection->executeQuery('SELECT 4');
    }
}
