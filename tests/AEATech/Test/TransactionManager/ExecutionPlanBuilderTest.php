<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\Attribute\DeferredBuild;
use AEATech\TransactionManager\ExecutionPlan;
use AEATech\TransactionManager\ExecutionPlanBuilder;
use AEATech\TransactionManager\Query;
use AEATech\TransactionManager\TransactionInterface;
use InvalidArgumentException;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ExecutionPlanBuilder::class)]
#[CoversClass(ExecutionPlan::class)]
class ExecutionPlanBuilderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @throws Throwable
     */
    #[Test]
    #[DataProvider('singleTransactionDataProvider')]
    public function buildWithSingleTransaction(bool $idempotent): void
    {
        $builder = new ExecutionPlanBuilder();

        $query = new Query('...');
        $tx = m::mock(TransactionInterface::class);
        $tx->shouldReceive('build')->once()->andReturn($query);
        $tx->shouldReceive('isIdempotent')->once()->andReturn($idempotent);

        $plan = $builder->build($tx);
        $queries = iterator_to_array($plan->getQueries());

        self::assertSame($idempotent, $plan->isIdempotent);
        self::assertCount(1, $queries);
        self::assertSame($query, $queries[0]);
    }

    public static function singleTransactionDataProvider(): array
    {
        return [
            ['idempotent' => true],
            ['idempotent' => false],
        ];
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function buildWithMultipleTransactionsAndAggregateIdempotency(): void
    {
        $builder = new ExecutionPlanBuilder();

        $q1 = new Query('...');
        $q2 = new Query('...');

        $t1 = m::mock(TransactionInterface::class);
        $t1->shouldReceive('build')->once()->andReturn($q1);
        $t1->shouldReceive('isIdempotent')->once()->andReturn(true);

        $t2 = m::mock(TransactionInterface::class);
        $t2->shouldReceive('build')->once()->andReturn($q2);
        $t2->shouldReceive('isIdempotent')->once()->andReturn(false);

        $plan = $builder->build([$t1, $t2]);
        $queries = iterator_to_array($plan->getQueries());

        self::assertFalse($plan->isIdempotent, 'Idempotency must be aggregated via logical AND');
        self::assertSame([$q1, $q2], $queries);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function buildThrowsForEmptyIterable(): void
    {
        $builder = new ExecutionPlanBuilder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one Transaction is required');

        $builder->build([]);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function buildThrowsForInvalidElementType(): void
    {
        $builder = new ExecutionPlanBuilder();

        $invalid = new class() {
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All elements of the iterable must implement TransactionInterface');

        $builder->build([$invalid]);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function buildPropagatesExceptionFromTransactionBuildAndStopsProcessing(): void
    {
        $builder = new ExecutionPlanBuilder();

        $t1 = m::mock(TransactionInterface::class);
        $t1->shouldReceive('build')->once()->andThrow(new RuntimeException('boom'));
        // isIdempotent() should never be called on t1 due to early exception
        $t1->shouldNotReceive('isIdempotent');

        $t2 = m::mock(TransactionInterface::class);
        // Neither build() nor isIdempotent() should be called on t2 due to an early exception
        $t2->shouldNotReceive('build');
        $t2->shouldNotReceive('isIdempotent');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $builder->build([$t1, $t2]);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function buildDoesNotCallBuildForDeferredTransaction(): void
    {
        $builder = new ExecutionPlanBuilder();

        $tx = new #[DeferredBuild] class implements TransactionInterface {
            public function build(): Query { throw new RuntimeException('Should not be called'); }
            public function isIdempotent(): bool { return true; }
        };

        $plan = $builder->build($tx);

        self::assertTrue($plan->isIdempotent);

        // build() is only called during iteration
        $iterator = $plan->getQueries();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Should not be called');
        iterator_to_array($iterator);
    }
}
