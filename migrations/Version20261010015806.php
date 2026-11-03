<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010015806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist rider-to-restaurant distance when a rider accepts a pool offer.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rider_pool_offers ADD accepted_pickup_distance_km NUMERIC(8, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rider_pool_offers DROP accepted_pickup_distance_km');
    }
}
