<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

enum Isolation: string
{
    /**
     * Dirty reads OK
     */
    case ReadUncommitted = 'READ UNCOMMITTED';

    /**
     * Default, no dirty reads
     */
    case ReadCommitted = 'READ COMMITTED';

    /**
     * No non-repeatable reads
     */
    case RepeatableRead = 'REPEATABLE READ';

    /**
     * Strongest, no phantom reads
     */
    case Serializable  = 'SERIALIZABLE';
}
