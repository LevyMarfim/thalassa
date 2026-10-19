<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds ticker.name.
 *
 * This migration originally contained `DROP INDEX uniq_account_name_per_user`,
 * which make:migration proposed because Doctrine cannot express a functional
 * index (user_id, LOWER(TRIM(name))) in attributes and so reports it as an
 * unknown index to delete. Running it silently removed the per-user account
 * name uniqueness guarantee. The line has been removed here, and
 * Version20261018230000 restores the index on databases where it was already
 * dropped.
 */
final class Version20261018224338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ticker.name: the human-readable security name.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticker ADD name VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticker DROP name');
    }
}
