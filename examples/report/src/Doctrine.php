<?php

declare(strict_types=1);

namespace Doctrine\DBAL {
    final class Connection
    {
        public function executeQuery(string $sql): void {}
        public function executeStatement(string $sql): void {}
    }
}

namespace Doctrine\ORM {
    final class Query
    {
        public function getResult(): array { return []; }
    }
}
