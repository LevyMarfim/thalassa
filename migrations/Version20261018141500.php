<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Operation types must be unique, ignoring case and surrounding whitespace.
 *
 * Doctrine attributes cannot describe a functional index, so this migration is
 * hand-written and the mapping deliberately stays unaware of it. The expression
 * must stay identical to OperationTypeRepository::findOneByTypeIgnoreCase(),
 * otherwise the form and the database would disagree about what is a duplicate.
 */
final class Version20261018141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dedupe operation_type and enforce case/whitespace-insensitive uniqueness.';
    }

    public function up(Schema $schema): void
    {
        // Repoint transactions at the surviving (lowest-id) row of each
        // duplicate group before the duplicates disappear, so no transaction
        // is left referencing a deleted operation type.
        $this->addSql(<<<'SQL'
            UPDATE app_transaction t
            SET operation_type_id = d.keep_id
            FROM (
                SELECT id AS dup_id,
                       FIRST_VALUE(id) OVER (
                           PARTITION BY LOWER(TRIM(type)) ORDER BY id
                       ) AS keep_id
                FROM operation_type
            ) d
            WHERE t.operation_type_id = d.dup_id
              AND d.dup_id <> d.keep_id
        SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM operation_type o
            WHERE EXISTS (
                SELECT 1
                FROM operation_type o2
                WHERE LOWER(TRIM(o2.type)) = LOWER(TRIM(o.type))
                  AND o2.id < o.id
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_operation_type_name
                ON operation_type (LOWER(TRIM(type)))
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_operation_type_name');
    }
}
