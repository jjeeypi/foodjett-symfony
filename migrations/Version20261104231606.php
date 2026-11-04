<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104231606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add favorites: restaurants a customer saved.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE favorites (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, customer_id BIGINT UNSIGNED NOT NULL, restaurant_id BIGINT UNSIGNED NOT NULL, UNIQUE INDEX favorites_customer_restaurant_unique (customer_id, restaurant_id), INDEX IDX_E46960F59395C3F3 (customer_id), INDEX IDX_E46960F5B1E7706E (restaurant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_E46960F59395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_E46960F5B1E7706E FOREIGN KEY (restaurant_id) REFERENCES restaurants (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE favorites DROP FOREIGN KEY FK_E46960F59395C3F3');
        $this->addSql('ALTER TABLE favorites DROP FOREIGN KEY FK_E46960F5B1E7706E');
        $this->addSql('DROP TABLE favorites');
    }
}
