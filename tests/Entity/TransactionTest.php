<?php

namespace App\Tests\Entity;

use App\Entity\Transaction;
use App\Enum\OperationEnum;
use PHPUnit\Framework\TestCase;

/**
 * The stored total carries the operation's direction, so the column itself can
 * be summed. Both setters normalise because Symfony binds form fields in
 * declaration order, and fixtures do not always follow it.
 */
final class TransactionTest extends TestCase
{
    public function testDebitStoresANegativeTotal(): void
    {
        $transaction = new Transaction();
        $transaction->setOperation(OperationEnum::DEBIT);
        $transaction->setTotalPrice(500.00);

        self::assertSame(-500.00, $transaction->getTotalPrice());
        self::assertSame(-500.00, $transaction->getSignedTotalPrice());
    }

    public function testCreditStoresAPositiveTotal(): void
    {
        $transaction = new Transaction();
        $transaction->setOperation(OperationEnum::CREDIT);
        $transaction->setTotalPrice(500.00);

        self::assertSame(500.00, $transaction->getTotalPrice());
        self::assertSame(500.00, $transaction->getSignedTotalPrice());
    }

    public function testOperationSetAfterTheTotalStillFixesTheSign(): void
    {
        $transaction = new Transaction();
        $transaction->setTotalPrice(500.00);
        $transaction->setOperation(OperationEnum::DEBIT);

        self::assertSame(-500.00, $transaction->getTotalPrice());
    }

    public function testTotalSetAfterTheOperationKeepsTheSign(): void
    {
        $transaction = new Transaction();
        $transaction->setOperation(OperationEnum::DEBIT);
        $transaction->setTotalPrice(-500.00);

        // A magnitude and an already-signed value both land on -500.
        self::assertSame(-500.00, $transaction->getTotalPrice());
    }

    public function testSwitchingOperationRewritesTheStoredSign(): void
    {
        $transaction = new Transaction();
        $transaction->setOperation(OperationEnum::DEBIT);
        $transaction->setTotalPrice(500.00);
        $transaction->setOperation(OperationEnum::CREDIT);

        self::assertSame(500.00, $transaction->getTotalPrice());
    }

    public function testNullTotalStaysNull(): void
    {
        $transaction = new Transaction();
        $transaction->setOperation(OperationEnum::DEBIT);
        $transaction->setTotalPrice(null);

        self::assertNull($transaction->getTotalPrice());
        self::assertSame(0.0, $transaction->getSignedTotalPrice());
    }
}
