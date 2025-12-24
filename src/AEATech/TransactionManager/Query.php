<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class Query
{
    /**
     * @param array<string|int, mixed> $params
     * @param array<string|int, mixed> $types
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params = [],
        public readonly array $types = [],
        public readonly StatementReusePolicy $statementReusePolicy = StatementReusePolicy::None
    ) {
    }
}
