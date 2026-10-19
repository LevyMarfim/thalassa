<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Restore uniq_account_name_per_user.
 *
 * Version20261018224338 originally dropped this index (see its docblock for
 * why that line was wrong). It was dropped on any database that ran before
 * that migration was corrected, so the guarantee is re-created here.
 *
 * IF NOT EXISTS keeps this a no-op on fresh databases, where the corrected
 * Version20261018224338 never removes it. The definition must stay in sync
 * with AccountRepository::findOneByNameForUser() and the UniqueEntity
 * repositoryMethod it backs, or form-level and database-level uniqueness
 * would disagree about what counts as a duplicate.
 */
final class Version20261018230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore the per-user account name uniqueness index lost in Version20261018224338.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_account_name_per_user
                ON app_account (user_id, LOWER(TRIM(name)))
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_account_name_per_user');
    }
}
