<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use Throwable;

interface ErrorClassifierInterface
{
    public function classify(Throwable $e): ErrorType;
}
