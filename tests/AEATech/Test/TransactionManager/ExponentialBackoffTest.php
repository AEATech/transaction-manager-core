<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\ExponentialBackoff;
use AEATech\TransactionManager\TimeUnit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class ExponentialBackoffTest extends TransactionManagerTestCase
{
    /**
     * @throws Throwable
     */
    #[Test]
    public function delayWithoutJitterIsDeterministic(): void
    {
        $b = new ExponentialBackoff(baseDelayMs: 100, maxDelayMs: 5000, multiplier: 2.0, jitterMs: 0);

        $d0 = $b->delay(0);

        self::assertSame(100, $d0->value);
        self::assertSame(TimeUnit::Milliseconds, $d0->unit);

        $d1 = $b->delay(1);

        self::assertSame(200, $d1->value);
        self::assertSame(TimeUnit::Milliseconds, $d1->unit);

        $d2 = $b->delay(2);

        self::assertSame(400, $d2->value);

        $d3 = $b->delay(3);

        self::assertSame(800, $d3->value);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function delayIsCappedByMaxWithoutJitter(): void
    {
        $b = new ExponentialBackoff(baseDelayMs: 1000, maxDelayMs: 2500, multiplier: 3.0, jitterMs: 0);

        // attempt 0: 1000
        self::assertSame(1000, $b->delay(0)->value);

        // attempt 1: 3000 -> capped to 2500
        self::assertSame(2500, $b->delay(1)->value);

        // attempt 2+: still capped at 2500
        self::assertSame(2500, $b->delay(2)->value);
        self::assertSame(2500, $b->delay(5)->value);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function delayIncludesJitterWithinRangeAndRespectsCap(): void
    {
        $b = new ExponentialBackoff(baseDelayMs: 100, maxDelayMs: 500, multiplier: 2.0, jitterMs: 100);

        // attempt 0: deterministic part = 100, range [100, 200]
        $d0 = $b->delay(0)->value;

        self::assertGreaterThanOrEqual(100, $d0);
        self::assertLessThanOrEqual(200, $d0);

        // attempt 2: deterministic part = 400, range [400, 500], but cap=500 + jitter still applies to added part only,
        // formula is min(max, deterministic) + random(0, jitter), so base part is capped at 500, then add jitter up to 100
        $d2 = $b->delay(2)->value; // deterministic part = 400 (no cap yet)

        self::assertGreaterThanOrEqual(400, $d2);
        self::assertLessThanOrEqual(500, $d2); // 400 + up to 100

        // attempt 3: deterministic part = 800 -> capped to 500, then + jitter [0..100] => [500..600]
        $d3 = $b->delay(3)->value;

        self::assertGreaterThanOrEqual(500, $d3);
        self::assertLessThanOrEqual(600, $d3);
    }

    #[Test]
    public function createWithNegativeBaseDelayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base delay must be non-negative');

        new ExponentialBackoff(-1);
    }

    #[Test]
    public function createWithMaxLessThanBaseThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum delay must be greater than or equal to base delay');

        new ExponentialBackoff(200, 100);
    }

    #[Test]
    public function createWithNegativeJitterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jitter must be non-negative');

        new ExponentialBackoff(100, 1000, 2.0, -5);
    }

    #[Test]
    #[DataProvider('invalidMultiplierProvider')]
    public function createWithInvalidMultiplierThrows(float $multiplier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiplier must be greater than 1.0');

        new ExponentialBackoff(100, 1000, $multiplier, 0);
    }

    public static function invalidMultiplierProvider(): array
    {
        return [
            ['multiplier' => 1.0],
            ['multiplier' => 0.0],
            ['multiplier' => -2.5],
        ];
    }
}
