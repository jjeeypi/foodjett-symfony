<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103235001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store ratings and weekday numbers as SMALLINT UNSIGNED so the schema matches the Doctrine mapping';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE restaurant_operating_hours CHANGE day_of_week day_of_week SMALLINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE restaurant_reviews CHANGE rating rating SMALLINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE rider_reviews CHANGE rating rating SMALLINT UNSIGNED NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE restaurant_operating_hours CHANGE day_of_week day_of_week TINYINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE restaurant_reviews CHANGE rating rating TINYINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE rider_reviews CHANGE rating rating TINYINT UNSIGNED NOT NULL');
    }
}
