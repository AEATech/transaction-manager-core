<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\Attribute\DeferredBuild;
use AEATech\TransactionManager\Query;
use AEATech\TransactionManager\ReflectionDeferredBuildResolver;
use AEATech\TransactionManager\TransactionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionException;

#[CoversClass(ReflectionDeferredBuildResolver::class)]
class ReflectionDeferredBuildResolverTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    #[Test]
    public function itReturnsTrueWhenAttributeIsPresent(): void
    {
        $resolver = new ReflectionDeferredBuildResolver();
        $tx = new #[DeferredBuild] class implements TransactionInterface {
            public function build(): Query { return new Query('...'); }
            public function isIdempotent(): bool { return true; }
        };

        self::assertTrue($resolver->isDeferredBuild($tx));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function itReturnsFalseWhenAttributeIsMissing(): void
    {
        $resolver = new ReflectionDeferredBuildResolver();
        $tx = new class implements TransactionInterface {
            public function build(): Query { return new Query('...'); }
            public function isIdempotent(): bool { return true; }
        };

        self::assertFalse($resolver->isDeferredBuild($tx));
    }
}
