<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class Query
{
    public function __construct(
        public readonly string $sql,
        public readonly array $params = [],
        public readonly array $types = [],
        public readonly StatementReusePolicy $statementReusePolicy = StatementReusePolicy::None
    ) {
    }
}
