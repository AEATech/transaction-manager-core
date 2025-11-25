<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\Exception;

use RuntimeException;
use Throwable;

class UnknownCommitStateException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(
            'The transaction commit failed in an unknown state. ' .
            'The operation might have succeeded or failed. ' .
            'Manual reconciliation is required because the operation is not idempotent.',
            0,
            $previous
        );
    }
}
