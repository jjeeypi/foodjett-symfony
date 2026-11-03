<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010013212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store rider payout account details as application-encrypted text.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE riders CHANGE payout_account_details payout_account_details TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE riders CHANGE payout_account_details payout_account_details JSON DEFAULT NULL');
    }
}
