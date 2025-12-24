<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use AEATech\TransactionManager\Exception\UnknownCommitStateException;
use LogicException;
use Throwable;

interface TransactionManagerInterface
{
    /**
     * Execute one or multiple business transactions within a single DB transaction.
     *
     * Rules:
     * - If a single Transaction is passed - executes as a single operation
     * - If iterable<Transaction> is passed - all elements are executed atomically as a batch
     *
     * Flow:
     * 0. Pre-build: Before the DB transaction starts, collect a Query list via tx.build() (no I/O)
     *    - EXCEPT for Transactions marked with #[DeferredBuild] attribute: build() is called later inside the DB transaction
     * 1. BEGIN TRANSACTION with a specified isolation level
     * 2. Execute all prepared Queries using executeQuery()
     *    - For #[DeferredBuild] Transactions: build() is called here (inside the transaction) and then executed
     * 3. COMMIT with UnknownCommit detection
     * 4. On error: rollback and retry if transient error
     *    - Normally, retry doesn't rebuild Queries (a pre-built result is reused)
     *    - For #[DeferredBuild] Transactions: build() is called AGAIN on every retry
     *
     * #### Contracts and Guarantees
     * - `run($txs, $opt)`:
     *   - Preconditions:
     *     - `retries ≥ 0`.
     *     - If `$txs` is `iterable<Transaction>`: each `Transaction` must be stateless/pure
     *       (repeated `build()` calls are deterministic), UNLESS marked with #[DeferredBuild].
     *   - Postconditions (success):
     *     - For a single `Transaction`: returns `RunResult(affected)` from the single `execute`.
     *     - For `iterable<Transaction>`: returns `RunResult(totalAffected)` - sum of `affected` across all elements.
     *   - Exceptions:
     *     - All vendor/DBAL exceptions are propagated as-is.
     *
     * @param TransactionInterface|iterable $txs Single transaction or collection of transactions
     * @phpstan-param TransactionInterface|iterable<TransactionInterface> $txs
     * @param TxOptions $opt Transaction options (isolation level, retry settings)
     *
     * @return RunResult Contains execution results (e.g., affected rows)
     *
     * @throws UnknownCommitStateException
     * @throws LogicException Thrown when the TransactionManager cannot safely take control over the database transaction
     * — For example, the underlying connection already has an active transaction
     * (TransactionManager requires exclusive control over BEGIN/COMMIT/ROLLBACK);
     * @throws Throwable
     */
    public function run(TransactionInterface|iterable $txs, TxOptions $opt = new TxOptions()): RunResult;
}
