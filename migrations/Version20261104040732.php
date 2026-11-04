<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104040732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cart_items for the database-saved customer cart.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cart_items (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, addon_ids JSON NOT NULL, quantity SMALLINT UNSIGNED NOT NULL, special_instructions LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, customer_id BIGINT UNSIGNED NOT NULL, menu_item_id BIGINT UNSIGNED NOT NULL, variant_id BIGINT UNSIGNED DEFAULT NULL, INDEX IDX_BEF484459395C3F3 (customer_id), INDEX IDX_BEF484459AB44FE0 (menu_item_id), INDEX IDX_BEF484453B69A9AF (variant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cart_items ADD CONSTRAINT FK_BEF484459395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cart_items ADD CONSTRAINT FK_BEF484459AB44FE0 FOREIGN KEY (menu_item_id) REFERENCES menu_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cart_items ADD CONSTRAINT FK_BEF484453B69A9AF FOREIGN KEY (variant_id) REFERENCES menu_item_variants (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cart_items DROP FOREIGN KEY FK_BEF484459395C3F3');
        $this->addSql('ALTER TABLE cart_items DROP FOREIGN KEY FK_BEF484459AB44FE0');
        $this->addSql('ALTER TABLE cart_items DROP FOREIGN KEY FK_BEF484453B69A9AF');
        $this->addSql('DROP TABLE cart_items');
    }
}
