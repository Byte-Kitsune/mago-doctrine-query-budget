<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;

final class SearchGateway
{
    public function search(string $term): array
    {
        return [$term];
    }
}

final class TryController
{
    public function __construct(private Connection $connection, private SearchGateway $search) {}

    public function index(): void
    {
        try {
            $this->search->search('example');
            $this->connection->executeQuery('SELECT 1');
        } catch (\RuntimeException $error) {
            $this->connection->executeStatement('SELECT 2');
        } finally {
            $this->connection->executeQuery('SELECT 3');
            $this->connection->executeQuery('SELECT 4');
        }
    }
}

final class SearchOnlyController
{
    public function __construct(private SearchGateway $search) {}

    public function index(): void
    {
        try {
            $this->search->search('example');
        } catch (\RuntimeException $error) {
            return;
        }
    }
}

final class UnknownTryController
{
    public function index(): void
    {
        try {
            \json_encode(new \stdClass());
        } catch (\RuntimeException $error) {
            return;
        }
    }
}
