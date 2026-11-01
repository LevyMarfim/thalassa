<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelTest extends KernelTestCase
{
    /**
     * FrankenPHP's reset-kernel mode clones the kernel after every request.
     * The clone must release the connections opened by that request instead
     * of leaving them for the garbage collector, or PostgreSQL eventually
     * refuses new clients with "too many clients already".
     */
    public function testCloningTheKernelClosesOpenConnections(): void
    {
        $kernel = self::bootKernel();

        $connection = self::getContainer()->get('doctrine')->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        $connection->executeQuery('SELECT 1');
        self::assertTrue($connection->isConnected());

        clone $kernel;

        self::assertFalse($connection->isConnected());
    }
}
