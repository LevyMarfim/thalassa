<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018132942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope app_transaction to a user (user_id NOT NULL) so transactions are per-account.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_transaction ADD user_id INT NOT NULL');
        $this->addSql('ALTER TABLE app_transaction ADD CONSTRAINT FK_2BD2236DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2BD2236DA76ED395 ON app_transaction (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_transaction DROP CONSTRAINT FK_2BD2236DA76ED395');
        $this->addSql('DROP INDEX IDX_2BD2236DA76ED395');
        $this->addSql('ALTER TABLE app_transaction DROP user_id');
    }
}
