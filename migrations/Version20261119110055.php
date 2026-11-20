<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give every account its own chart colour.
 *
 * Existing accounts are assigned the same palette in id order that the create
 * form now uses for new ones, so a user with several accounts gets distinct
 * dashboard lines immediately instead of every line sharing the default green.
 */
final class Version20261119110055 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a per-account chart colour and backfill existing accounts with distinct palette colours.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_account ADD color VARCHAR(7) DEFAULT \'#10b981\' NOT NULL');

        $this->addSql(<<<'SQL'
            UPDATE app_account a
               SET color = palette.color
              FROM (
                    SELECT id,
                           CASE (ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY id) - 1) % 8
                               WHEN 0 THEN '#10b981'
                               WHEN 1 THEN '#3b82f6'
                               WHEN 2 THEN '#f59e0b'
                               WHEN 3 THEN '#8b5cf6'
                               WHEN 4 THEN '#ef4444'
                               WHEN 5 THEN '#06b6d4'
                               WHEN 6 THEN '#ec4899'
                               WHEN 7 THEN '#84cc16'
                           END AS color
                      FROM app_account
                   ) palette
             WHERE palette.id = a.id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_account DROP color');
    }
}
