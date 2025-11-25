<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use PHPUnit\Framework\TestCase;
use Mockery;

abstract class TransactionManagerTestCase extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Mockery::close();
    }
}
