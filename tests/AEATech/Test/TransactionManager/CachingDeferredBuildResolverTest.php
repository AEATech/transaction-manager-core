<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\CachingDeferredBuildResolver;
use AEATech\TransactionManager\DeferredBuildResolverInterface;
use AEATech\TransactionManager\Query;
use AEATech\TransactionManager\TransactionInterface;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionException;

#[CoversClass(CachingDeferredBuildResolver::class)]
class CachingDeferredBuildResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function itCallsDelegateAndCachesResult(): void
    {
        $tx = m::mock(TransactionInterface::class);
        $delegate = m::mock(DeferredBuildResolverInterface::class);

        $delegate->shouldReceive('isDeferredBuild')
            ->once()
            ->with($tx)
            ->andReturn(true);

        $resolver = new CachingDeferredBuildResolver($delegate);

        // The first call should call delegate
        self::assertTrue($resolver->isDeferredBuild($tx));

        // Second call should return cached value (delegate only called once)
        self::assertTrue($resolver->isDeferredBuild($tx));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function itCachesSeparatelyForDifferentClasses(): void
    {
        $delegate = m::mock(DeferredBuildResolverInterface::class);

        $tx1 = new class implements TransactionInterface {
            public function build(): Query { return new Query('...'); }
            public function isIdempotent(): bool { return true; }
        };
        $tx2 = new class implements TransactionInterface {
            public function build(): Query { return new Query('...'); }
            public function isIdempotent(): bool { return true; }
        };

        $delegate->shouldReceive('isDeferredBuild')
            ->once()
            ->with($tx1)
            ->andReturn(true);

        $delegate->shouldReceive('isDeferredBuild')
            ->once()
            ->with($tx2)
            ->andReturn(false);

        $resolver = new CachingDeferredBuildResolver($delegate);

        self::assertTrue($resolver->isDeferredBuild($tx1));
        self::assertFalse($resolver->isDeferredBuild($tx2));

        // Repeated calls
        self::assertTrue($resolver->isDeferredBuild($tx1));
        self::assertFalse($resolver->isDeferredBuild($tx2));
    }
}
