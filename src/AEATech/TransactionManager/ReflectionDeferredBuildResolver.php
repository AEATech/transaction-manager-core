<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use AEATech\TransactionManager\Attribute\DeferredBuild;
use ReflectionClass;

class ReflectionDeferredBuildResolver implements DeferredBuildResolverInterface
{
    public function isDeferredBuild(TransactionInterface $tx): bool
    {
        return [] !== (new ReflectionClass($tx))->getAttributes(DeferredBuild::class);
    }
}
