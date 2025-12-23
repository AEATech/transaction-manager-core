<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\IsolationLevel;
use AEATech\TransactionManager\RetryPolicy;
use AEATech\TransactionManager\TxOptions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Mockery as m;

class TxOptionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    public function readUncommitted(): void
    {
        $retryPolicy = m::mock(RetryPolicy::class);
        $options = TxOptions::readUncommitted($retryPolicy);

        self::assertSame(IsolationLevel::ReadUncommitted, $options->isolationLevel);
        self::assertSame($retryPolicy, $options->retryPolicy);
    }

    #[Test]
    public function readCommitted(): void
    {
        $options = TxOptions::readCommitted();

        self::assertSame(IsolationLevel::ReadCommitted, $options->isolationLevel);
        self::assertNull($options->retryPolicy);
    }

    #[Test]
    public function repeatableRead(): void
    {
        $options = TxOptions::repeatableRead();

        self::assertSame(IsolationLevel::RepeatableRead, $options->isolationLevel);
        self::assertNull($options->retryPolicy);
    }

    #[Test]
    public function serializable(): void
    {
        $options = TxOptions::serializable();

        self::assertSame(IsolationLevel::Serializable, $options->isolationLevel);
        self::assertNull($options->retryPolicy);
    }
}
