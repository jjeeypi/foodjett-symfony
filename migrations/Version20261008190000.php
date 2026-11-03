<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the fixed rider pool incentive amount setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
INSERT INTO platform_settings (`key`, `value`, `description`, `created_at`, `updated_at`)
SELECT 'rider_incentive_amount', '20.00', 'Fixed rider pay incentive added at the incentivized pool stage', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM platform_settings WHERE `key` = 'rider_incentive_amount')
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM platform_settings WHERE `key` = 'rider_incentive_amount'");
    }
}
