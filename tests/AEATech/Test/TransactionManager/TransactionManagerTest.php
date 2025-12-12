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

        $opt = new TxOptions(IsolationLevel::RepeatableRead);

        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        $this->mockBeginTransactionWithOptions($opt);
        $this->conn->shouldReceive('executeQuery')->once()->with($q1)->andReturn(1);
        $this->conn->shouldReceive('executeQuery')->once()->with($q2)->andReturn(3);
        $this->conn->shouldReceive('commit')->once();

        // No retries, so no sleeper/backoff, no rollback
        $this->conn->shouldNotReceive('rollBack');
        $this->sleeper->shouldNotReceive('sleep');
        $this->classifier->shouldNotReceive('classify');


        $res = $this->tm->run([$this, $this], $opt); // txs ignored by builder mock

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
        $q1 = new Query('DO');
        $plan = new ExecutionPlan(true, [$q1]);

        $opt = new TxOptions();

        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        $this->mockBeginTransactionWithOptions($opt);

        $err = new RuntimeException('boom');
        $this->expectExecThrowsAndRollbackThenClassify($err, ErrorType::Transient, $q1);
        $this->sleeper->shouldNotReceive('sleep');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');


        $this->tm->run([$this], $opt);
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

        $opt = new TxOptions();

        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        $this->mockBeginTransactionWithOptions($opt);

        $err = new RuntimeException('fatal');
        $this->expectExecThrowsAndRollbackThenClassify($err, ErrorType::Fatal);
        $this->sleeper->shouldNotReceive('sleep');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fatal');

        $this->tm->run([$this], $opt);
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

        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(2, $backoff));

        // First attempt
        $this->mockBeginTransactionWithOptions($opt, times: 2);

        $err = new RuntimeException('transient');
        $this->expectExecThrowsAndRollbackThenClassify($err, ErrorType::Transient);

        // Backoff for attempt 0
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(5));
        $this->sleeper->shouldReceive('sleep')
            ->once()
            ->with(m::on(static function (Duration $d) {
                return $d->toMicroseconds() === 5_000;
            }));

        // Second attempt succeeds
        $this->conn->shouldReceive('executeQuery')->once()->andReturn(2);
        $this->conn->shouldReceive('commit')->once();

        $res = $this->tm->run([$this], $opt);

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

        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(1, $backoff));

        // Attempt 0 fails with a connection error
        $this->mockBeginTransactionWithOptions($opt, 2);
        $err = new RuntimeException('conn');
        $this->expectExecThrowsAndRollbackThenClassify($err, ErrorType::Connection);
        $this->conn->shouldReceive('close')->once();
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(1));
        $this->sleeper->shouldReceive('sleep')->once();

        // Attempt 1 OK
        $this->conn->shouldReceive('executeQuery')->once()->andReturn(1);
        $this->conn->shouldReceive('commit')->once();


        $res = $this->tm->run([$this], $opt);

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

        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(2, $backoff));

        $q = new Query('Q');
        $plan = new ExecutionPlan(true, [$q]);
        $this->builder->shouldReceive('build')->once()->andReturn($plan);

        $this->mockBeginTransactionWithOptions($opt, 3);

        // Three attempts all fail with transient; policy allows 2 retries (maxRetries=2)
        // Attempt 0
        $err0 = new RuntimeException('t0');
        $this->expectExecThrowsAndRollbackThenClassify($err0, ErrorType::Transient);
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(1));
        $this->sleeper->shouldReceive('sleep')->once();

        // Attempt 1
        $err1 = new RuntimeException('t1');
        $this->expectExecThrowsAndRollbackThenClassify($err1, ErrorType::Transient);
        $backoff->shouldReceive('delay')->once()->with(1)->andReturn(Duration::milliseconds(2));
        $this->sleeper->shouldReceive('sleep')->once();

        // Attempt 2 (last allowed) fails and must be thrown (no further sleep)
        $err2 = new RuntimeException('t2');
        $this->expectExecThrowsAndRollbackThenClassify($err2, ErrorType::Transient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('t2');

        $this->tm->run([$this], $opt);
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

        $opt = new TxOptions();

        $this->mockBeginTransactionWithOptions($opt);

        $this->conn->shouldReceive('executeQuery')->once()->andReturn(1);
        $err = new RuntimeException('commit failed');
        $this->conn->shouldReceive('commit')->once()->andThrow($err);
        $this->conn->shouldReceive('rollBack')->once();

        // No classification should be needed because UnknownCommitState is thrown before classifying
        $this->classifier->shouldNotReceive('classify');
        $this->sleeper->shouldNotReceive('sleep');

        $this->expectException(UnknownCommitStateException::class);
        $this->tm->run([$this], $opt);
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

        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(1, $backoff));

        $this->mockBeginTransactionWithOptions($opt, 2);

        // Attempt 0: executes fine, commit fails
        $this->conn->shouldReceive('executeQuery')->once()->andReturn(1);
        $cErr = new RuntimeException('commit transient');
        $this->expectCommitThrowsRollbackThenClassify($cErr);
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(1));
        $this->sleeper->shouldReceive('sleep')->once();

        // Attempt 1: succeeds
        $this->conn->shouldReceive('executeQuery')->once()->andReturn(1);
        $this->conn->shouldReceive('commit')->once();


        $res = $this->tm->run([$this], $opt);

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

        $opt = new TxOptions(IsolationLevel::ReadCommitted, new RetryPolicy(1, $backoff));

        // First attempt: begin throws immediately, should close and begin again,
        // then execute throws to force a retry path
        $beginErr0 = new RuntimeException('gone away');
        $this->conn->shouldReceive('beginTransactionWithOptions')
            ->once()
            ->with($opt)
            ->andThrow($beginErr0);
        $this->conn->shouldReceive('close')->once();
        $this->conn->shouldReceive('beginTransactionWithOptions')->once()->with($opt); // retry begins

        $execErr = new RuntimeException('deadlock');
        $this->expectExecThrowsAndRollbackThenClassify($execErr, ErrorType::Transient);
        $backoff->shouldReceive('delay')->once()->with(0)->andReturn(Duration::milliseconds(1));
        $this->sleeper->shouldReceive('sleep')->once();

        // Second attempt: begin throws again, but now allowReconnect=false so it should propagate without close()
        $beginErr1 = new RuntimeException('begin fail attempt1');
        $this->conn->shouldReceive('beginTransactionWithOptions')->once()->with($opt)->andThrow($beginErr1);

        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($beginErr1)->andReturn(ErrorType::Fatal);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('begin fail attempt1');

        $this->tm->run([$this], $opt);
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

        $opt = new TxOptions();
        $this->mockBeginTransactionWithOptions($opt);

        $execErr = new RuntimeException('exec');
        $this->conn->shouldReceive('executeQuery')->once()->andThrow($execErr);
        // rollBack itself throws, but TransactionManager must swallow it
        $this->conn->shouldReceive('rollBack')->once()->andThrow(new RuntimeException('rb fail'));

        $this->classifier->shouldReceive('classify')->once()->with($execErr)->andReturn(ErrorType::Fatal);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exec');

        $this->tm->run([$this], $opt);
    }

    private function mockBeginTransactionWithOptions(TxOptions $options, int $times = 1): void
    {
        $this->conn->shouldReceive('beginTransactionWithOptions')
            ->times($times)
            ->with($options);
    }

    private function expectExecThrowsAndRollbackThenClassify(Throwable $err, ErrorType $type, ?Query $q = null): void
    {
        $exp = $this->conn->shouldReceive('executeQuery')->once();
        if ($q !== null) {
            $exp->with($q);
        }
        $exp->andThrow($err);

        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($err)->andReturn($type);
    }

    private function expectCommitThrowsRollbackThenClassify(Throwable $err): void
    {
        $this->conn->shouldReceive('commit')->once()->andThrow($err);
        $this->conn->shouldReceive('rollBack')->once();
        $this->classifier->shouldReceive('classify')->once()->with($err)->andReturn(ErrorType::Transient);
    }
}
