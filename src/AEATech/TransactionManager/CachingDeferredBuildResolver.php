<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class CachingDeferredBuildResolver implements DeferredBuildResolverInterface
{
    /**
     * @var array<class-string, bool>
     */
    private array $cache = [];

    public function __construct(
        private readonly DeferredBuildResolverInterface $delegate = new ReflectionDeferredBuildResolver()
    ) {
    }

    public function isDeferredBuild(TransactionInterface $tx): bool
    {
        $className = $tx::class;

        return $this->cache[$className] ??= $this->delegate->isDeferredBuild($tx);
    }
}
