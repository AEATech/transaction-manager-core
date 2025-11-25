<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

interface SleeperInterface
{
    public function sleep(Duration $duration): void;
}
