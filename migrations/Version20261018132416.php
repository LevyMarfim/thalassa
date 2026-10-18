<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018132416 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_transaction ADD operation VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE app_transaction ADD description VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE app_transaction ADD total_price DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE app_transaction ADD operation_type_id INT NOT NULL');
        $this->addSql('ALTER TABLE app_transaction ADD ticker_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_transaction ADD CONSTRAINT FK_2BD2236D668D0C5E FOREIGN KEY (operation_type_id) REFERENCES operation_type (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE app_transaction ADD CONSTRAINT FK_2BD2236D556B180E FOREIGN KEY (ticker_id) REFERENCES ticker (id)');
        $this->addSql('CREATE INDEX IDX_2BD2236D668D0C5E ON app_transaction (operation_type_id)');
        $this->addSql('CREATE INDEX IDX_2BD2236D556B180E ON app_transaction (ticker_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_transaction DROP CONSTRAINT FK_2BD2236D668D0C5E');
        $this->addSql('ALTER TABLE app_transaction DROP CONSTRAINT FK_2BD2236D556B180E');
        $this->addSql('DROP INDEX IDX_2BD2236D668D0C5E');
        $this->addSql('DROP INDEX IDX_2BD2236D556B180E');
        $this->addSql('ALTER TABLE app_transaction DROP operation');
        $this->addSql('ALTER TABLE app_transaction DROP description');
        $this->addSql('ALTER TABLE app_transaction DROP total_price');
        $this->addSql('ALTER TABLE app_transaction DROP operation_type_id');
        $this->addSql('ALTER TABLE app_transaction DROP ticker_id');
    }
}
