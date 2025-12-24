<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\DatabaseErrorHeuristicsInterface;
use AEATech\TransactionManager\ErrorType;
use AEATech\TransactionManager\GenericErrorClassifier;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(GenericErrorClassifier::class)]
class GenericErrorClassifierTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private DatabaseErrorHeuristicsInterface&m\MockInterface $heuristics;
    private GenericErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->heuristics = m::mock(DatabaseErrorHeuristicsInterface::class);
        $this->classifier = new GenericErrorClassifier($this->heuristics);
    }

    #[Test]
    public function classifyReturnsConnectionWhenHeuristicsSaysConnection(): void
    {
        $e = new RuntimeException('connection dropped', 0);

        $this->heuristics->shouldReceive('isConnectionIssue')
            ->once()
            ->with(null, null, 'connection dropped')
            ->andReturnTrue();

        $this->heuristics->shouldNotReceive('isTransientIssue');

        $type = $this->classifier->classify($e);

        self::assertSame(ErrorType::Connection, $type);
    }

    #[Test]
    public function classifyReturnsTransientWhenHeuristicsSaysTransient(): void
    {
        $e = new RuntimeException('please retry later', 0);

        $this->heuristics->shouldReceive('isConnectionIssue')
            ->once()
            ->with(null, null, 'please retry later')
            ->andReturnFalse();

        $this->heuristics->shouldReceive('isTransientIssue')
            ->once()
            ->with(null, null, 'please retry later')
            ->andReturnTrue();

        $type = $this->classifier->classify($e);

        self::assertSame(ErrorType::Transient, $type);
    }

    #[Test]
    public function classifyReturnsFatalWhenNoHeuristicsMatch(): void
    {
        $e = new RuntimeException('boom');

        $this->heuristics->shouldReceive('isConnectionIssue')
            ->once()
            ->with(null, null, 'boom')
            ->andReturnFalse();

        $this->heuristics->shouldReceive('isTransientIssue')
            ->once()
            ->with(null, null, 'boom')
            ->andReturnFalse();

        $type = $this->classifier->classify($e);

        self::assertSame(ErrorType::Fatal, $type);
    }

    #[Test]
    public function classifyUsesDeepestCauseFirst(): void
    {
        $inner = new RuntimeException('inner cause');
        $outer = new RuntimeException('outer cause', 0, $inner);

        // The classifier must inspect the deepest cause first ("inner cause") and
        // return immediately on a match, without touching the outer exception.
        $this->heuristics->shouldReceive('isConnectionIssue')
            ->once()
            ->with(null, null, 'inner cause')
            ->andReturnFalse();

        $this->heuristics->shouldReceive('isTransientIssue')
            ->once()
            ->with(null, null, 'inner cause')
            ->andReturnTrue();

        // Ensure outer is never consulted
        $this->heuristics->shouldNotReceive('isConnectionIssue')->withArgs(function ($a, $b, $c) {
            return $c === 'outer cause';
        });
        $this->heuristics->shouldNotReceive('isTransientIssue')->withArgs(function ($a, $b, $c) {
            return $c === 'outer cause';
        });

        $type = $this->classifier->classify($outer);

        self::assertSame(ErrorType::Transient, $type);
    }

    #[Test]
    public function extractPrefersPdoExceptionErrorInfo(): void
    {
        $pdoEx = new PDOException('deadlock found when trying to get lock');
        // Compose errorInfo: [sqlstate, driverCode, driverMessage]
        $pdoEx->errorInfo = ['40001', 1213, 'Deadlock'];

        $this->heuristics->shouldReceive('isConnectionIssue')
            ->once()
            ->with('40001', 1213, 'deadlock found when trying to get lock')
            ->andReturnFalse();

        $this->heuristics->shouldReceive('isTransientIssue')
            ->once()
            ->with('40001', 1213, 'deadlock found when trying to get lock')
            ->andReturnTrue();

        $type = $this->classifier->classify($pdoEx);

        self::assertSame(ErrorType::Transient, $type);
    }

    #[Test]
    public function doesNotPromoteZeroCodeAsDriverCode(): void
    {
        $e = new RuntimeException('retry by message only', 0);

        $this->heuristics->shouldReceive('isConnectionIssue')
            ->once()
            ->with(null, null, 'retry by message only')
            ->andReturnFalse();

        $this->heuristics->shouldReceive('isTransientIssue')
            ->once()
            ->with(null, null, 'retry by message only')
            ->andReturnTrue();

        $type = $this->classifier->classify($e);

        self::assertSame(ErrorType::Transient, $type);
    }

    #[Test]
    public function promotesNonZeroIntCodeAsDriverCode(): void
    {
        $e = new RuntimeException('vendor code present', 1062); // non-zero int

        $this->heuristics->shouldReceive('isConnectionIssue')
            ->once()
            ->with(null, 1062, 'vendor code present')
            ->andReturnFalse();

        $this->heuristics->shouldReceive('isTransientIssue')
            ->once()
            ->with(null, 1062, 'vendor code present')
            ->andReturnFalse();

        $type = $this->classifier->classify($e);

        self::assertSame(ErrorType::Fatal, $type);
    }

    #[Test]
    public function fallsBackToGetSqlStateMethodWhenAvailable(): void
    {
        $ex = new class('serialization failure') extends RuntimeException {
            public function getSQLState(): string
            {
                return '40P01';
            }
        };

        $this->heuristics->shouldReceive('isConnectionIssue')
            ->once()
            ->with('40P01', null, 'serialization failure')
            ->andReturnFalse();

        $this->heuristics->shouldReceive('isTransientIssue')
            ->once()
            ->with('40P01', null, 'serialization failure')
            ->andReturnTrue();

        $type = $this->classifier->classify($ex);

        self::assertSame(ErrorType::Transient, $type);
    }

    #[Test]
    public function stringCodeOfFiveCharsIsUsedAsSqlState(): void
    {
        $ex = new class('serialization failure via code') extends PDOException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                // Simulate PDO-style string code (SQLSTATE) without errorInfo
                $this->code = '40001';
            }
        };

        $this->heuristics
            ->shouldReceive('isConnectionIssue')
            ->once()
            ->with('40001', null, 'serialization failure via code')
            ->andReturnFalse();

        $this->heuristics
            ->shouldReceive('isTransientIssue')
            ->once()
            ->with('40001', null, 'serialization failure via code')
            ->andReturnTrue();

        $type = $this->classifier->classify($ex);

        self::assertSame(ErrorType::Transient, $type);
    }

    #[Test]
    public function stringCodeLongerThanFiveUsesFirstFiveChars(): void
    {
        $ex = new class('unique violation via code') extends PDOException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '23505: duplicate key value violates unique constraint';
            }
        };

        $this->heuristics
            ->shouldReceive('isConnectionIssue')
            ->once()
            ->with('23505', null, 'unique violation via code')
            ->andReturnFalse();

        $this->heuristics
            ->shouldReceive('isTransientIssue')
            ->once()
            ->with('23505', null, 'unique violation via code')
            ->andReturnFalse();

        $type = $this->classifier->classify($ex);

        self::assertSame(ErrorType::Fatal, $type);
    }

    #[Test]
    public function stringCodeTakesPrecedenceOverGetSqlState(): void
    {
        $ex = new class('code beats method') extends PDOException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '40001';
            }
            public function getSQLState(): string
            {
                return '40P01';
            }
        };

        // The classifier should use the string code (40001) and never consult getSQLState()
        // because sqlState becomes non-null before the reflective check.
        $this->heuristics
            ->shouldReceive('isConnectionIssue')
            ->once()
            ->with('40001', null, 'code beats method')
            ->andReturnFalse();

        $this->heuristics
            ->shouldReceive('isTransientIssue')
            ->once()
            ->with('40001', null, 'code beats method')
            ->andReturnTrue();

        $type = $this->classifier->classify($ex);

        self::assertSame(ErrorType::Transient, $type);
    }
}
