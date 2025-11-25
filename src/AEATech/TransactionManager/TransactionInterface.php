<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use Throwable;

interface TransactionInterface
{
    /**
     * Requirements:
     * - MUST return the same Query on repeated calls (stateless/pure, deterministic)
     * - Does NOT perform I/O and does not access DB/network (pure function)
     * - Called BEFORE DB transaction starts and is NOT called again on retries (build() result is cached during run)
     *
     * @throws Throwable
     */
    public function build(): Query;

    /**
     * Answers whether the transaction can be safely repeated in case of an error.
     */
    public function isIdempotent(): bool;
}
