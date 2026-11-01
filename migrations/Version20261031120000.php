<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normalise transaction totals to a signed amount: credits positive, debits
 * negative.
 *
 * The form accepted either spelling and nothing normalised it on write, so the
 * same debit could be stored as 500 or -500 depending on how it was entered.
 * Transaction::setTotalPrice()/setOperation() now enforce the sign on every
 * write; this migration brings the rows already in the table in line, so a
 * future SUM(total_price) is correct without a CASE expression.
 *
 * Only rows whose sign is wrong are touched, which keeps the migration a no-op
 * on a database that has none.
 */
final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store transaction totals signed: debits negative, credits positive.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE app_transaction
               SET total_price = -ABS(total_price)
             WHERE operation = 'DEBIT'
               AND total_price > 0
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE app_transaction
               SET total_price = ABS(total_price)
             WHERE operation = 'CREDIT'
               AND total_price < 0
        SQL);
    }

    public function down(Schema $schema): void
    {
        // The pre-normalisation sign is not recoverable: both 500 and -500 were
        // valid input for the same debit. Revert to the positive magnitude,
        // which is what the form defaulted to and what the readers tolerated.
        $this->addSql('UPDATE app_transaction SET total_price = ABS(total_price)');
    }
}
