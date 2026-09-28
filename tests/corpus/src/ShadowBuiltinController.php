<?php

declare(strict_types=1);

namespace App\Shadow;

use Doctrine\DBAL\Connection;

function max(Connection $connection): void
{
    $connection->executeQuery('SELECT 1');
}

final class ShadowBuiltinController
{
    public function index(Connection $connection): void
    {
        max($connection);
    }
}
