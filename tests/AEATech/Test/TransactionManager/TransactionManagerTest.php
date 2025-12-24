<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\BackoffStrategyInterface;
use AEATech\TransactionManager\ConnectionInterface;
use AEATech\TransactionManager\ErrorClassifierInterface;
use AEATech\TransactionManager\Attribute\DeferredBuild;
use AEATech\TransactionManager\RunResult;
use AEATech\TransactionManager\TransactionInterface;
use AEATech\TransactionManager\ErrorType;
use AEATech\TransactionManager\ExecutionPlan;
use AEATech\TransactionManager\ExecutionPlanBuilderInterface;
use AEATech\TransactionManager\Exception\UnknownCommitStateException;
use AEATech\TransactionManager\IsolationLevel;
use AEATech\TransactionManager\Query;
use AEATech\TransactionManager\RetryPolicy;
use AEATech\TransactionManager\SleeperInterface;
use AEATech\TransactionManager\TransactionManager;
use AEATech\TransactionManager\TxOptions;
use AEATech\TransactionManager\Duration;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(TransactionManager::class)]
#[CoversClass(UnknownCommitStateException::class)]
#[CoversClass(Query::class)]
#[CoversClass(RunResult::class)]
class TransactionManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ExecutionPlanBuilderInterface&m\MockInterface $executionPlanBuilder;
    private ConnectionInterface&m\MockInterface $connection;
    private ErrorClassifierInterface&m\MockInterface $errorClassifier;
    private SleeperInterface&m\MockInterface $sleeper;
    private TransactionManager $tm;
    private TransactionInterface&m\MockInterface $tx;
    private Query $defaultQuery;
    private TxOptions $defaultTxOptions;
    private BackoffStrategyInterface&m\MockInterface $defaultBackoff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executionPlanBuilder = m::mock(ExecutionPlanBuilderInterface::class);
        $this->connection = m::mock(ConnectionInterface::class);
        $this->errorClassifier = m::mock(ErrorClassifierInterface::class);
        $this->sleeper = m::mock(SleeperInterface::class);
        $defaultRetryPolicy = RetryPolicy::noRetry();

        $this->tm = new TransactionManager(
            $this->executionPlanBuilder,
            $this->connection,
            $this->errorClassifier,
            $defaultRetryPolicy,
            $this->sleeper
        );
        $this->tx = m::mock(TransactionInterface::class);
        $this->defaultQuery = new Query('default');
        $this->defaultTxOptions = new TxOptions();
        $this->defaultBackoff = m::mock(BackoffStrategyInterface::class);
    }

    /**
     * Happy path: executes queries, commits, returns total affected rows. Also verifies isolation is set.
     *
     * @throws Throwable
     */
    #[Test]
    public function runHappyPath(): void
    {
        $q1 = new Query('INSERT ...', ['a' => 1], ['a' => 'int']);
        $q2 = new Query('UPDATE ...', ['id' => 5], ['id' => 'int']);

        $plan = new ExecutionPlan(isIdempotent: true, steps: [$q1, $q2]);

        $tr1 = m::mock(TransactionInterface::class);
        $tr2 = m::mock(TransactionInterface::class);

        $txs = [
            $tr1,
            $tr2,
        ];

        $this->executionPlanBuilder->shouldReceive('build')
            ->once()
            ->with($txs)
            ->andReturn($plan);

        $opt = new TxOptions(IsolationLevel::RepeatableRead);

        $this->mockBeginTransactionWithOptions($opt);

        $this->mockExecuteQuery($q1, 1);
        $this->mockExecuteQuery($q2, 3);

        $this->mockCommit();

        // No retries, so no sleeper/backoff, no rollback
        $this->connection->shouldNotReceive('rollBack');
        $this->sleeper->shouldNotReceive('sleep');
        $this->errorClassifier->shouldNotReceive('classify');

        $res = $this->tm->run($txs, $opt);

        self::assertSame(4, $res->affectedRows);
    }

    /**
     * When executeQuery throws a transient error and no RetryPolicy provided,
     * it must roll back and rethrow immediately.
     *
     * @throws Throwable
     */
    #[Test]
    public function executeErrorWithoutRetryPolicyPropagates(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);
        $this->mockBeginTransactionWithOptions($this->defaultTxOptions);

        $err = new RuntimeException('boom');

        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err, ErrorType::Transient);

        $this->sleeper->shouldNotReceive('sleep');

        $this->expectExceptionObject($err);

        $this->tm->run($this->tx);
    }

    /**
     * Fatal classification must propagate without retries and without sleeping.
     *
     * @throws Throwable
     */
    #[Test]
    public function fatalErrorPropagatesImmediately(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);
        $this->mockBeginTransactionWithOptions($this->defaultTxOptions);

        $err = new RuntimeException('fatal');

        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err, ErrorType::Fatal);

        $this->sleeper->shouldNotReceive('sleep');

        $this->expectExceptionObject($err);

        $this->tm->run($this->tx);
    }

    /**
     * Retryable transient error with RetryPolicy: retry happens, sleep called with backoff attempt 0, then success.
     * Also verifies builder->build called once, and queries reused.
     *
     * @throws Throwable
     */
    #[Test]
    public function transientErrorWithPolicyRetriesAndSucceeds(): void
    {
        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(2, $this->defaultBackoff));

        $this->mockExecutionPlanBuilder($this->defaultQuery);

        // First attempt
        $this->mockBeginTransactionWithOptions($opt, times: 2);

        $err = new RuntimeException('transient');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err, ErrorType::Transient);

        // Backoff for attempt 0
        $this->mockGetDelayAndSleep(delayMs: 5);

        // Second attempt succeeds
        $this->mockExecuteQuery($this->defaultQuery, 2);

        $this->mockCommit();

        $res = $this->tm->run($this->tx, $opt);

        self::assertSame(2, $res->affectedRows);
    }

    /**
     * Connection error requires explicit connection->close() before retry.
     *
     * @throws Throwable
     */
    #[Test]
    public function connectionErrorClosesBeforeRetry(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);

        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(1, $this->defaultBackoff));

        // Attempt 0 fails with a connection error
        $this->mockBeginTransactionWithOptions($opt, 2);

        $err = new RuntimeException('conn');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err, ErrorType::Connection);

        $this->mockConnectionClose();

        $this->mockGetDelayAndSleep(delayMs: 1);

        // Attempt 1 OK
        $this->mockExecuteQuery($this->defaultQuery, 1);

        $this->mockCommit();

        $res = $this->tm->run($this->tx, $opt);

        self::assertSame(1, $res->affectedRows);
    }

    /**
     * Exceeding max retries: throws after retries exhausted; backoff called for attempts 0...max-1.
     *
     * @throws Throwable
     */
    #[Test]
    public function exceedsMaxRetriesThrowsLastError(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);

        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(2, $this->defaultBackoff));

        $this->mockBeginTransactionWithOptions($opt, 3);

        // Three attempts all fail with transient; policy allows 2 retries (maxRetries=2)
        // Attempt 0
        $err0 = new RuntimeException('t0');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err0, ErrorType::Transient);
        $this->mockGetDelayAndSleep(delayMs: 1);

        // Attempt 1
        $err1 = new RuntimeException('t1');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err1, ErrorType::Transient);
        $this->mockGetDelayAndSleep(delayMs: 2, attempt: 1);

        // Attempt 2 (last allowed) fails and must be thrown (no further sleep)
        $err2 = new RuntimeException('t2');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err2, ErrorType::Transient);

        $this->expectExceptionObject($err2);

        $this->tm->run($this->tx, $opt);
    }

    /**
     * Commit failure with a non-idempotent plan must throw UnknownCommitStateException immediately.
     *
     * @throws Throwable
     */
    #[Test]
    public function commitErrorOnNonIdempotentThrowsUnknownState(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery, isIdempotent: false);
        $this->mockBeginTransactionWithOptions($this->defaultTxOptions);

        $this->mockExecuteQuery($this->defaultQuery, 1);

        $err = new RuntimeException('commit failed');
        $this->connection->shouldReceive('commit')->once()->andThrow($err);
        $this->mockRollback();

        // No classification should be needed because UnknownCommitState is thrown before classifying
        $this->errorClassifier->shouldNotReceive('classify');
        $this->sleeper->shouldNotReceive('sleep');

        $this->expectException(UnknownCommitStateException::class);

        $this->tm->run($this->tx);
    }

    /**
     * Commit failure with idempotent plan should be retried per policy and succeed on the next attempt.
     *
     * @throws Throwable
     */
    #[Test]
    public function commitErrorOnIdempotentRetriesAndSucceeds(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);

        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(1, $this->defaultBackoff));

        $this->mockBeginTransactionWithOptions($opt, 2);

        // Attempt 0: executes fine, commit fails
        $this->mockExecuteQuery($this->defaultQuery, rowsAffected: 1, times: 2);

        $cErr = new RuntimeException('commit transient');

        $this->connection->shouldReceive('commit')->once()->andThrow($cErr);

        $this->mockRollback();

        $this->mockClassifyError($cErr, ErrorType::Transient);

        $this->mockGetDelayAndSleep(delayMs: 1);

        // Attempt 1: succeeds
        $this->mockCommit();

        $res = $this->tm->run($this->tx, $opt);

        self::assertSame(1, $res->affectedRows);
    }

    /**
     * beginTransaction reconnect logic: on the first attempt only, if begin throws, the manager closes,
     * and retries begin once.
     *
     * Later attempts do not perform reconnecting.
     *
     * @throws Throwable
     */
    #[Test]
    public function beginReconnectOnFirstAttemptOnly(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);

        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(1, $this->defaultBackoff));

        // First attempt: begin throws immediately, should close and begin again,
        // then execute throws to force a retry path
        $beginErr0 = new RuntimeException('gone away');

        $this->mockBeginTransactionWithOptionsWithErr($opt, $beginErr0);

        $this->mockConnectionClose();

        $this->mockBeginTransactionWithOptions($opt); // retry begins

        $execErr = new RuntimeException('deadlock');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $execErr, ErrorType::Transient);

        $this->mockGetDelayAndSleep(delayMs: 1);

        // Second attempt: begin throws again, but now allowReconnect=false so it should propagate without close()
        $beginErr1 = new RuntimeException('begin fail attempt1');

        $this->mockBeginTransactionWithOptionsWithErr($opt, $beginErr1);

        $this->mockRollback();

        $this->mockClassifyError($beginErr1, ErrorType::Fatal);

        $this->expectExceptionObject($beginErr1);

        $this->tm->run($this->tx, $opt);
    }

    /**
     * safeRollback must swallow rollback exceptions and continue to classification/handling.
     *
     * @throws Throwable
     */
    #[Test]
    public function safeRollbackSwallowsErrors(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);

        $this->mockBeginTransactionWithOptions($this->defaultTxOptions);

        $err = new RuntimeException('exec');

        $this->mockExecuteQueryWithErr($this->defaultQuery, $err);

        // rollBack itself throws, but TransactionManager must swallow it
        $this->connection->shouldReceive('rollBack')
            ->once()
            ->andThrow(new RuntimeException('rb fail'));

        $this->mockClassifyError($err, ErrorType::Fatal);

        $this->expectExceptionObject($err);

        $this->tm->run($this->tx);
    }

    /**
     * Verifies that the defaultRetryPolicy provided in the constructor is used when TxOptions doesn't override it.
     *
     * @throws Throwable
     */
    #[Test]
    public function usesDefaultRetryPolicyFromConstructor(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);

        // First attempt fails, second succeeds (because defaultPolicy has maxRetries=1)
        $this->mockBeginTransactionWithOptions($this->defaultTxOptions, 2);

        $err = new RuntimeException('transient');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err, ErrorType::Transient);

        $this->mockGetDelayAndSleep(delayMs: 1);

        $this->mockExecuteQuery($this->defaultQuery, 1);
        $this->mockCommit();

        $defaultPolicy = new RetryPolicy(maxRetries: 1, backoffStrategy: $this->defaultBackoff);

        $tm = new TransactionManager(
            $this->executionPlanBuilder,
            $this->connection,
            $this->errorClassifier,
            $defaultPolicy,
            $this->sleeper
        );

        $res = $tm->run($this->tx);

        self::assertSame(1, $res->affectedRows);
    }

    /**
     * Verifies that the retryPolicy in TxOptions overrides the defaultRetryPolicy.
     *
     * @throws Throwable
     */
    #[Test]
    public function txOptionsOverrideDefaultRetryPolicy(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);

        // Options policy: 1 retry
        $opt = new TxOptions(retryPolicy: new RetryPolicy(1, $this->defaultBackoff));

        $this->mockBeginTransactionWithOptions($opt, 2);

        $err = new RuntimeException('transient');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err, ErrorType::Transient);

        $this->mockGetDelayAndSleep(delayMs: 1);

        $this->mockExecuteQuery($this->defaultQuery, 1);

        $this->mockCommit();

        // Constructor policy: no retries
        $tm = new TransactionManager(
            $this->executionPlanBuilder,
            $this->connection,
            $this->errorClassifier,
            RetryPolicy::noRetry(),
            $this->sleeper
        );

        $res = $tm->run($this->tx, $opt);

        self::assertSame(1, $res->affectedRows);
    }

    /**
     * Verifies the semantic of maxRetries: total executions = 1 + maxRetries.
     * Example: maxRetries = 2 -> 3 total attempts.
     *
     * @throws Throwable
     */
    #[Test]
    public function verifiesMaxRetriesSemantic(): void
    {
        $this->mockExecutionPlanBuilder($this->defaultQuery);

        // 3 attempts total
        $this->mockBeginTransactionWithOptions($this->defaultTxOptions, 3);

        // Attempt 0 fails
        $err0 = new RuntimeException('fail0');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err0, ErrorType::Transient);

        $this->mockGetDelayAndSleep(delayMs: 1);

        // Attempt 1 fails
        $err1 = new RuntimeException('fail1');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err1, ErrorType::Transient);

        $this->mockGetDelayAndSleep(delayMs: 2, attempt: 1);

        // Attempt 2 fails and propagates (exhausted)
        $err2 = new RuntimeException('fail2');
        $this->expectExecThrowsAndRollbackThenClassify($this->defaultQuery, $err2, ErrorType::Transient);

        $this->expectExceptionObject($err2);

        $tm = new TransactionManager(
            $this->executionPlanBuilder,
            $this->connection,
            $this->errorClassifier,
            new RetryPolicy(2, $this->defaultBackoff), // 2 additional retries -> 3 attempts total
            $this->sleeper
        );

        $tm->run($this->tx);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function deferredBuildCalledInsideTransactionOnEveryRetry(): void
    {
        $query1 = new Query('INSERT 1');
        $query2 = new Query('INSERT 2');

        $tx = new #[DeferredBuild] class($query1, $query2) implements TransactionInterface {
            public int $calls = 0;
            public function __construct(
                private readonly Query $q1,
                private readonly Query $q2
            ) {
            }
            public function build(): Query
            {
                $this->calls++;
                return $this->calls === 1 ? $this->q1 : $this->q2;
            }
            public function isIdempotent(): bool
            {
                return true;
            }
        };

        $this->executionPlanBuilder->shouldReceive('build')
            ->once()
            ->with($tx)
            ->andReturn(
                new ExecutionPlan(true, [$tx])
            );

        $opt = new TxOptions(retryPolicy: new RetryPolicy(1, $this->defaultBackoff));

        // First attempt fails during execution
        $this->mockBeginTransactionWithOptions($opt, times: 2);

        $err = new RuntimeException('transient');

        $this->expectExecThrowsAndRollbackThenClassify($query1, $err, ErrorType::Transient);

        $this->mockGetDelayAndSleep(delayMs: 1);

        // Second attempt succeeds
        $this->mockExecuteQuery($query2, 1);

        $this->mockCommit();

        $result = $this->tm->run($tx, $opt);

        self::assertSame(1, $result->affectedRows);
        self::assertSame(2, $tx->calls, 'build() should be called on every attempt');
    }

    private function mockExecutionPlanBuilder(Query $query, bool $isIdempotent = true): void
    {
        $steps = [
            $query,
        ];

        $plan = new ExecutionPlan($isIdempotent, $steps);

        $this->executionPlanBuilder->shouldReceive('build')
            ->once()
            ->with($this->tx)
            ->andReturn($plan);
    }

    private function mockExecuteQuery(Query $q, int $rowsAffected, int $times = 1): void
    {
        $this->connection->shouldReceive('executeQuery')
            ->times($times)
            ->with($q)
            ->andReturn($rowsAffected);
    }

    private function mockExecuteQueryWithErr(Query $q, Throwable $err): void
    {
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->with($q)
            ->andThrow($err);
    }

    private function mockCommit(): void
    {
        $this->connection->shouldReceive('commit')->once();
    }

    private function mockGetDelayAndSleep(int $delayMs, int $attempt = 0): void
    {
        // Backoff for attempt 0
        $this->defaultBackoff->shouldReceive('delay')
            ->once()
            ->with($attempt)
            ->andReturn(Duration::milliseconds($delayMs));

        $this->sleeper->shouldReceive('sleep')
            ->once()
            ->with(m::on(static function (Duration $d) use ($delayMs) {
                return $d->toMicroseconds() === $delayMs * 1000;
            }));
    }

    private function mockRollback(): void
    {
        $this->connection->shouldReceive('rollBack')->once();
    }

    private function mockConnectionClose(): void
    {
        $this->connection->shouldReceive('close')->once();
    }

    private function mockClassifyError(Throwable $err, ErrorType $type): void
    {
        $this->errorClassifier->shouldReceive('classify')
            ->once()
            ->with($err)
            ->andReturn($type);
    }

    private function mockBeginTransactionWithOptions(TxOptions $opt, int $times = 1): void
    {
        $this->connection->shouldReceive('beginTransactionWithOptions')
            ->times($times)
            ->with(m::on(static function (TxOptions $o) use ($opt): bool {
                return $o->isolationLevel === $opt->isolationLevel && $o->retryPolicy === $opt->retryPolicy;
            }));
    }

    private function mockBeginTransactionWithOptionsWithErr(TxOptions $opt, Throwable $err): void
    {
        $this->connection->shouldReceive('beginTransactionWithOptions')
            ->once()
            ->with(m::on(static function (TxOptions $o) use ($opt): bool {
                return $o->isolationLevel === $opt->isolationLevel && $o->retryPolicy === $opt->retryPolicy;
            }))
            ->andThrow($err);
    }

    private function expectExecThrowsAndRollbackThenClassify(Query $q, Throwable $err, ErrorType $type): void
    {
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->with($q)
            ->andThrow($err);

        $this->mockRollback();

        $this->mockClassifyError($err, $type);
    }
}
