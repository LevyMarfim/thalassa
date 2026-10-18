<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018131042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revert the operation_type -> OperationEnum link (dropped the operation column).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE operation_type DROP operation');
    }

    public function down(Schema $schema): void
    {
        // Restored as NULLABLE on purpose: the original values are not
        // recoverable, and a NOT NULL re-add would fail on any non-empty table.
        $this->addSql('ALTER TABLE operation_type ADD operation VARCHAR(255) DEFAULT NULL');
    }
}
