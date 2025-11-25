<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\BackoffStrategyInterface;
use AEATech\TransactionManager\RetryPolicy;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class RetryPolicyTest extends TransactionManagerTestCase
{
    /**
     * @throws Throwable
     */
    #[Test]
    #[DataProvider('createWithInvalidMaxRetriesDataProvider')]
    public function createWithInvalidMaxRetries(int $maxRetries): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Max retries must be at least 1. If you do not want retries, do not use a RetryPolicy.'
        );

        new RetryPolicy($maxRetries, m::mock(BackoffStrategyInterface::class));
    }

    public static function createWithInvalidMaxRetriesDataProvider(): array
    {
        return [
            [
                'maxRetries' => 0,
            ],
            [
                'maxRetries' => -1,
            ]
        ];
    }
}
