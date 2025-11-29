<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\BackoffStrategyInterface;
use AEATech\TransactionManager\ConnectionInterface;
use AEATech\TransactionManager\ErrorClassifierInterface;
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
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

class TransactionManagerTest extends TransactionManagerTestCase
{
    private ExecutionPlanBuilderInterface $builder;
    private ConnectionInterface $conn;
    private ErrorClassifierInterface $classifier;
    private SleeperInterface $sleeper;
    private TransactionManager $tm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = m::mock(ExecutionPlanBuilderInterface::class);
        $this->conn = m::mock(ConnectionInterface::class);
        $this->classifier = m::mock(ErrorClassifierInterface::class);
        $this->sleeper = m::mock(SleeperInterface::class);

        $this->tm = new TransactionManager($this->builder, $this->conn, $this->classifier, $this->sleeper);
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
        $plan = new ExecutionPlan(isIdempotent: true, queries: [$q1, $q2]);

        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::RepeatableRead);
        $this->conn->shouldReceive('executeStatement')->once()->with($q1->sql, $q1->params, $q1->types)->andReturn(1);
        $this->conn->shouldReceive('executeStatement')->once()->with($q2->sql, $q2->params, $q2->types)->andReturn(3);
        $this->conn->shouldReceive('commit')->once();

        // No retries, so no sleeper/backoff, no rollback
        $this->conn->shouldNotReceive('rollBack');
        $this->sleeper->shouldNotReceive('sleep');
        $this->classifier->shouldNotReceive('classify');

        $res = $this->tm->run([$this, $this], new TxOptions(IsolationLevel::RepeatableRead)); // txs ignored by builder mock

        self::assertSame(4, $res->affectedRows);
    }

    /**
     * When executeStatement throws a transient error and no RetryPolicy provided,
     * it must roll back and rethrow immediately.
     *
     * @throws Throwable
     */
    #[Test]
    public function executeErrorWithoutRetryPolicyPropagates(): void
    {
        $q1 = new Query('DO');
        $plan = new ExecutionPlan(true, [$q1]);

        $this->builder->shouldReceive('build')->once()->andReturn($plan);
        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);

        $err = new RuntimeException('boom');
        $this->conn->shouldReceive('executeStatement')->once()->with($q1->sql, $q1->params, $q1->types)->andThrow($err);
        $this->conn->shouldReceive('rollBack')->once();

        $this->classifier->shouldReceive('classify')->once()->with($err)->andReturn(ErrorType::Transient);
        $this->sleeper->shouldNotReceive('sleep');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->tm->run([$this], new TxOptions());
    }

    /**
     * Fatal classification must propagate without retries and without sleeping.
     *
     * @throws Throwable
     */
    #[Test]
    public function fatalErrorPropagatesImmediately(): void
    {
        $q1 = new Query('X');
        $plan = new ExecutionPlan(true, [$q1]);

        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);

        $err = new RuntimeException('fatal');
        $this->conn->shouldReceive('executeStatement')->once()->andThrow($err);
        $this->conn->shouldReceive('rollBack')->once();

        $this->classifier->shouldReceive('classify')->once()->with($err)->andReturn(ErrorType::Fatal);
        $this->sleeper->shouldNotReceive('sleep');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fatal');
        $this->tm->run([$this]);
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
        $backoff = m::mock(BackoffStrategyInterface::class);

        $q = new Query('UPDATE');
        $plan = new ExecutionPlan(true, [$q]);
        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        // First attempt
        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $err = new RuntimeException('transient');
        $this->conn->shouldReceive('executeStatement')->once()->andThrow($err);
        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($err)->andReturn(ErrorType::Transient);

        // Backoff for attempt 0
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(5));
        $this->sleeper->shouldReceive('sleep')
            ->once()
            ->with(m::on(static function (Duration $d) {
                return $d->toMicroseconds() === 5_000;
            }));

        // Second attempt succeeds
        $this->conn->shouldReceive('beginTransaction')->once();
        // IsolationLevel set again each attempt
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $this->conn->shouldReceive('executeStatement')->once()->andReturn(2);
        $this->conn->shouldReceive('commit')->once();

        $policy = new RetryPolicy(2, $backoff);
        $res = $this->tm->run([$this], new TxOptions(IsolationLevel::ReadCommitted, $policy));

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
        $backoff = m::mock(BackoffStrategyInterface::class);

        $q = new Query('SELECT 1');
        $plan = new ExecutionPlan(true, [$q]);
        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        // Attempt 0 fails with a connection error
        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $err = new RuntimeException('conn');
        $this->conn->shouldReceive('executeStatement')->once()->andThrow($err);
        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($err)->andReturn(ErrorType::Connection);
        $this->conn->shouldReceive('close')->once();
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(1));
        $this->sleeper->shouldReceive('sleep')->once();

        // Attempt 1 OK
        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $this->conn->shouldReceive('executeStatement')->once()->andReturn(1);
        $this->conn->shouldReceive('commit')->once();

        $res = $this->tm->run([$this], new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(1, $backoff)));
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
        $backoff = m::mock(BackoffStrategyInterface::class);

        $q = new Query('Q');
        $plan = new ExecutionPlan(true, [$q]);
        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        // Three attempts all fail with transient; policy allows 2 retries (maxRetries=2)
        // Attempt 0
        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $err0 = new RuntimeException('t0');
        $this->conn->shouldReceive('executeStatement')->once()->andThrow($err0);
        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($err0)->andReturn(ErrorType::Transient);
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(1));
        $this->sleeper->shouldReceive('sleep')->once();

        // Attempt 1
        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $err1 = new RuntimeException('t1');
        $this->conn->shouldReceive('executeStatement')->once()->andThrow($err1);
        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($err1)->andReturn(ErrorType::Transient);
        $backoff->shouldReceive('delay')->once()->with(1)->andReturn(Duration::milliseconds(2));
        $this->sleeper->shouldReceive('sleep')->once();

        // Attempt 2 (last allowed) fails and must be thrown (no further sleep)
        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $err2 = new RuntimeException('t2');
        $this->conn->shouldReceive('executeStatement')->once()->andThrow($err2);
        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($err2)->andReturn(ErrorType::Transient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('t2');

        $this->tm->run([$this], new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(2, $backoff)));
    }

    /**
     * Commit failure with a non-idempotent plan must throw UnknownCommitStateException immediately.
     *
     * @throws Throwable
     */
    #[Test]
    public function commitErrorOnNonIdempotentThrowsUnknownState(): void
    {
        $q = new Query('ins');
        $plan = new ExecutionPlan(false, [$q]); // non-idempotent
        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $this->conn->shouldReceive('executeStatement')->once()->andReturn(1);
        $err = new RuntimeException('commit failed');
        $this->conn->shouldReceive('commit')->once()->andThrow($err);
        $this->conn->shouldReceive('rollBack')->once();

        // No classification should be needed because UnknownCommitState is thrown before classifying
        $this->classifier->shouldNotReceive('classify');
        $this->sleeper->shouldNotReceive('sleep');

        $this->expectException(UnknownCommitStateException::class);
        $this->tm->run([$this]);
    }

    /**
     * Commit failure with idempotent plan should be retried per policy and succeed on the next attempt.
     *
     * @throws Throwable
     */
    #[Test]
    public function commitErrorOnIdempotentRetriesAndSucceeds(): void
    {
        $backoff = m::mock(BackoffStrategyInterface::class);

        $q = new Query('work');
        $plan = new ExecutionPlan(true, [$q]); // idempotent
        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        // Attempt 0: executes fine, commit fails
        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $this->conn->shouldReceive('executeStatement')->once()->andReturn(1);
        $cErr = new RuntimeException('commit transient');
        $this->conn->shouldReceive('commit')->once()->andThrow($cErr);
        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($cErr)->andReturn(ErrorType::Transient);
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(1));
        $this->sleeper->shouldReceive('sleep')->once();

        // Attempt 1: succeeds
        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $this->conn->shouldReceive('executeStatement')->once()->andReturn(1);
        $this->conn->shouldReceive('commit')->once();

        $res = $this->tm->run([$this], new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(1, $backoff)));
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
        $backoff = m::mock(BackoffStrategyInterface::class);

        $q = new Query('Q');
        $plan = new ExecutionPlan(true, [$q]);
        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        // First attempt: begin throws immediately, should close and begin again,
        // then execute throws to force a retry path
        $beginErr0 = new RuntimeException('gone away');
        $this->conn->shouldReceive('beginTransaction')->once()->andThrow($beginErr0);
        $this->conn->shouldReceive('close')->once();
        $this->conn->shouldReceive('beginTransaction')->once(); // retry begins
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);

        $execErr = new RuntimeException('deadlock');
        $this->conn->shouldReceive('executeStatement')->once()->andThrow($execErr);
        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($execErr)->andReturn(ErrorType::Transient);
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(1));
        $this->sleeper->shouldReceive('sleep')->once();

        // Second attempt: begin throws again, but now allowReconnect=false so it should propagate without close()
        $beginErr1 = new RuntimeException('begin fail attempt1');
        $this->conn->shouldReceive('beginTransaction')->once()->andThrow($beginErr1);
        // safeRollback should swallow exceptions if any; we won't expect setTransactionIsolationLevel on this path
        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($beginErr1)->andReturn(ErrorType::Fatal);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('begin fail attempt1');
        $this->tm->run([$this], new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(1, $backoff)));
    }

    /**
     * safeRollback must swallow rollback exceptions and continue to classification/handling.
     *
     * @throws Throwable
     */
    #[Test]
    public function safeRollbackSwallowsErrors(): void
    {
        $q = new Query('BROKEN');
        $plan = new ExecutionPlan(true, [$q]);
        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        $this->conn->shouldReceive('beginTransaction')->once();
        $this->conn->shouldReceive('setTransactionIsolationLevel')->once()->with(IsolationLevel::ReadCommitted);
        $execErr = new RuntimeException('exec');
        $this->conn->shouldReceive('executeStatement')->once()->andThrow($execErr);
        // rollBack itself throws, but TransactionManager must swallow it
        $this->conn->shouldReceive('rollBack')->once()->andThrow(new RuntimeException('rb fail'));

        $this->classifier->shouldReceive('classify')->once()->with($execErr)->andReturn(ErrorType::Fatal);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exec');

        $this->tm->run([$this]);
    }
}
