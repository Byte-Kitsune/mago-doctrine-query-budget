<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

final class Connection
{
    public function executeQuery(string $sql): void {}
    public function executeStatement(string $sql): void {}
    public function prepare(string $sql): Statement { return new Statement(); }
}

final class Statement
{
    public function execute(): void {}
}
