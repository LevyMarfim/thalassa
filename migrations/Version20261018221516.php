<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Transaction gains quantity/cost basis detail:
 *   amount   – how many units moved (fractional buys often have decimals,
 *              so SMALLINT as mapped; see note in the entity)
 *   unit_price / invoice / market – cost basis, brokerage note and B3 market
 *
 * Deliberately does NOT contain the `DROP INDEX uniq_account_name_per_user`
 * that make:migration proposed. That index is a hand-written functional index
 * (user_id, LOWER(TRIM(name))) which Doctrine cannot express in attributes, so
 * it always reports it as an unknown index to delete. Dropping it here would
 * silently remove the per-user account name uniqueness guarantee.
 */
final class Version20261018221516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add amount, unit_price, invoice and market to app_transaction.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_transaction ADD amount SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_transaction ADD unit_price DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE app_transaction ADD invoice INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_transaction ADD market VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_transaction DROP amount');
        $this->addSql('ALTER TABLE app_transaction DROP unit_price');
        $this->addSql('ALTER TABLE app_transaction DROP invoice');
        $this->addSql('ALTER TABLE app_transaction DROP market');
    }
}
