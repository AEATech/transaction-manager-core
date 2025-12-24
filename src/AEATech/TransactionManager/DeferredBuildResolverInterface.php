<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use ReflectionException;

interface DeferredBuildResolverInterface
{
    /**
     * @throws ReflectionException
     */
    public function isDeferredBuild(TransactionInterface $tx): bool;
}
