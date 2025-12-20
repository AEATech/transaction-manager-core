<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\BackoffStrategyInterface;
use AEATech\TransactionManager\NoBackoffStrategy;
use AEATech\TransactionManager\RetryPolicy;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class RetryPolicyTest extends TransactionManagerTestCase
{
    #[Test]
    #[DataProvider('newInstanceDataProvider')]
    public function newInstance(int $maxRetries): void
    {
        $backoffStrategy = m::mock(BackoffStrategyInterface::class);
        $policy = new RetryPolicy($maxRetries, $backoffStrategy);

        self::assertSame($maxRetries, $policy->maxRetries);
    }

    public static function newInstanceDataProvider(): array
    {
        return [
            [
                'maxRetries' => 0,
            ],
            [
                'maxRetries' => 1,
            ],
        ];
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[DataProvider('createWithInvalidMaxRetriesDataProvider')]
    public function createWithInvalidMaxRetries(int $maxRetries): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'maxRetries must be >= 0'
        );

        new RetryPolicy($maxRetries, m::mock(BackoffStrategyInterface::class));
    }

    public static function createWithInvalidMaxRetriesDataProvider(): array
    {
        return [
            [
                'maxRetries' => -1,
            ],
        ];
    }

    #[Test]
    public function noRetry(): void
    {
        $policy = RetryPolicy::noRetry();

        self::assertSame(0, $policy->maxRetries);
        self::assertEquals(new NoBackoffStrategy(), $policy->backoffStrategy);
    }
}
