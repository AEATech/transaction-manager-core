<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use AEATech\TransactionManager\Exception\UnknownCommitStateException;
use Throwable;

class TransactionManager implements TransactionManagerInterface
{
    public function __construct(
        private readonly ExecutionPlanBuilderInterface $executionPlanBuilder,
        private readonly ConnectionInterface $connection,
        private readonly ErrorClassifierInterface $errorClassifier,
        private readonly SleeperInterface $sleeper
    ) {
    }

    public function run(iterable|TransactionInterface $txs, TxOptions $opt = new TxOptions()): RunResult
    {
        $executionPlan = $this->executionPlanBuilder->build($txs);

        $attempt = 0;

        do {
            $total = 0;
            $isCommitting = false;

            try {
                /**
                 * Attempt to start a transaction.
                 * If this is the very first attempt (attempt === 0), we assume
                 * that the connection might have timed out ("gone away") in a long-running worker.
                 * In this case, we make one reconnection attempt "for free" (without incrementing the attempt counter).
                 */
                $this->beginTransaction($opt, allowReconnect: $attempt === 0);

                foreach ($executionPlan->queries as $query) {
                    $total += $this->connection->executeQuery($query);
                }

                $isCommitting = true;
                $this->connection->commit();
                $isCommitting = false;

                return new RunResult($total);
            } catch (Throwable $e) {
                $this->safeRollback();

                /**
                 * The error occurred during the commit phase.
                 * If the operations within the transaction are not idempotent, we cannot safely retry it.
                 * The system cannot guarantee whether the previous commit partially succeeded or failed,
                 * which may lead to duplicated side effects or an inconsistent state.
                 */
                if ($isCommitting && !$executionPlan->isIdempotent) {
                    throw new UnknownCommitStateException($e);
                }

                $errorType = $this->errorClassifier->classify($e);

                if ($errorType === ErrorType::Fatal) {
                    throw $e;
                }

                /**
                 * If RetryPolicy is not set, maxRetries = 0.
                 * If the attempt (0) >= 0 -> condition is met, throw an exception (no retries).
                 * If RetryPolicy is set (e.g., 3), then attempt (0) >= 3 -> false, proceed to retry.
                 */
                if ($opt->retryPolicy === null || $attempt >= $opt->retryPolicy->maxRetries) {
                    throw $e;
                }

                if ($errorType === ErrorType::Connection) {
                    $this->connection->close();
                }

                $this->sleeper->sleep($opt->retryPolicy->backoffStrategy->delay($attempt));

                $attempt++;
            }
        } while (true);
    }

    /**
     * Starts a transaction.
     * If $allowReconnect is true and the connection fails immediately,
     * it attempts to close the connection and start again (handling "MySQL gone away").
     *
     * @throws Throwable
     */
    private function beginTransaction(TxOptions $opt, bool $allowReconnect): void
    {
        try {
            $this->connection->beginTransactionWithOptions($opt);
        } catch (Throwable $e) {
            if ($allowReconnect) {
                /**
                 * If this is an old connection that has timed out,
                 * close it (reset state) and try again.
                 * Connection will automatically reconnect on the next beginTransaction call.
                 */
                $this->connection->close();
                $this->connection->beginTransactionWithOptions($opt);
            } else {
                throw $e;
            }
        }
    }

    private function safeRollback(): void
    {
        try {
            $this->connection->rollBack();
        } catch (Throwable) {
        }
    }
}
