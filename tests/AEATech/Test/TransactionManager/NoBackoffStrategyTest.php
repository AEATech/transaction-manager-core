<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\Duration;
use AEATech\TransactionManager\NoBackoffStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(NoBackoffStrategy::class)]
class NoBackoffStrategyTest extends TestCase
{
    /**
     * @throws Throwable
     */
    #[Test]
    #[DataProvider('delayDataProvider')]
    public function delay(int $attempt): void
    {
        self::assertEquals(Duration::zero(), (new NoBackoffStrategy())->delay($attempt));
    }

    public static function delayDataProvider(): array
    {
        return [
            [
                'attempt' => 0
            ],
            [
                'attempt' => 1
            ],
        ];
    }
}
