<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use Throwable;

interface TransactionInterface
{
    /**
     * Requirements:
     * - MUST return the same Query on repeated calls (stateless/pure, deterministic)
     * - Called BEFORE DB transaction starts and is NOT called again on retries (build() result is cached during run)
     * - EXCEPT when the class is marked with #[DeferredBuild] attribute:
     *   - build() is called INSIDE the active DB transaction
     *   - it MAY perform I/O (e.g., SELECT) through shared repositories
     *   - it is called on EVERY retry (to allow re-fetching changed DB state)
     *
     * @throws Throwable
     */
    public function build(): Query;

    /**
     * Answers whether the transaction can be safely repeated in case of an error.
     */
    public function isIdempotent(): bool;
}
