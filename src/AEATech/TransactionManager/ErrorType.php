<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

enum ErrorType
{
    /**
     * Temporary error (e.g., deadlock).
     * Action: Retry immediately (or with backoff).
     */
    case Transient;

    /**
     * Connection issue (e.g., server gone away).
     * Action: Reconnect, then retry.
     */
    case Connection;

    /**
     * Permanent error (e.g., syntax error).
     * Action: Do not retry, propagate exception.
     */
    case Fatal;
}
