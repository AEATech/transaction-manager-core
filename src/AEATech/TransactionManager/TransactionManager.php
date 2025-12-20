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
        private readonly RetryPolicy $defaultRetryPolicy,
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

                foreach ($executionPlan->getQueries() as $query) {
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

                $retryPolicy = $opt->retryPolicy ?? $this->defaultRetryPolicy;

                /**
                 * Check if we have exceeded the maximum number of allowed retries.
                 *
                 * Example with maxRetries = 3:
                 * - Attempt 0 (initial run): fails -> 0 >= 3 is false -> retry allowed.
                 * - Attempt 1 (1st retry):   fails -> 1 >= 3 is false -> retry allowed.
                 * - Attempt 2 (2nd retry):   fails -> 2 >= 3 is false -> retry allowed.
                 * - Attempt 3 (3rd retry):   fails -> 3 >= 3 is true  -> throw exception.
                 * Total executions: 1 (initial) + 3 (retries) = 4.
                 */
                if ($attempt >= $retryPolicy->maxRetries) {
                    throw $e;
                }

                if ($errorType === ErrorType::Connection) {
                    $this->connection->close();
                }

                $this->sleeper->sleep($retryPolicy->backoffStrategy->delay($attempt));

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
