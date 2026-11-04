<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104010005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen every money column to DECIMAL(10,2)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE menu_item_addons CHANGE price price NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE menu_item_variants CHANGE price_delta price_delta NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE menu_items CHANGE base_price base_price NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE order_item_addons CHANGE price price NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE order_items CHANGE unit_price unit_price NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE orders CHANGE subtotal subtotal NUMERIC(10, 2) NOT NULL, CHANGE delivery_fee delivery_fee NUMERIC(10, 2) NOT NULL, CHANGE service_fee service_fee NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, CHANGE discount_amount discount_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, CHANGE tip_amount tip_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, CHANGE total_amount total_amount NUMERIC(10, 2) NOT NULL, CHANGE commission_amount commission_amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments CHANGE amount amount NUMERIC(10, 2) NOT NULL, CHANGE refunded_amount refunded_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE restaurants CHANGE min_order_amount min_order_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE rider_cash_remittances CHANGE amount amount NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE rider_earnings CHANGE base_pay base_pay NUMERIC(10, 2) NOT NULL, CHANGE distance_pay distance_pay NUMERIC(10, 2) NOT NULL, CHANGE waiting_pay waiting_pay NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, CHANGE incentive_pay incentive_pay NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, CHANGE tip_amount tip_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, CHANGE total_earned total_earned NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE rider_pool_offers CHANGE incentive_amount incentive_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE riders CHANGE cash_on_hand cash_on_hand NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, CHANGE cash_remit_limit cash_remit_limit NUMERIC(10, 2) DEFAULT \'2000.00\' NOT NULL');
        $this->addSql('ALTER TABLE voucher_redemptions CHANGE discount_applied discount_applied NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE vouchers CHANGE value value NUMERIC(10, 2) DEFAULT NULL, CHANGE min_order_amount min_order_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE menu_items CHANGE base_price base_price NUMERIC(8, 2) NOT NULL');
        $this->addSql('ALTER TABLE menu_item_addons CHANGE price price NUMERIC(8, 2) NOT NULL');
        $this->addSql('ALTER TABLE menu_item_variants CHANGE price_delta price_delta NUMERIC(8, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE orders CHANGE subtotal subtotal NUMERIC(8, 2) NOT NULL, CHANGE delivery_fee delivery_fee NUMERIC(8, 2) NOT NULL, CHANGE service_fee service_fee NUMERIC(8, 2) DEFAULT \'0.00\' NOT NULL, CHANGE discount_amount discount_amount NUMERIC(8, 2) DEFAULT \'0.00\' NOT NULL, CHANGE tip_amount tip_amount NUMERIC(8, 2) DEFAULT \'0.00\' NOT NULL, CHANGE total_amount total_amount NUMERIC(8, 2) NOT NULL, CHANGE commission_amount commission_amount NUMERIC(8, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_items CHANGE unit_price unit_price NUMERIC(8, 2) NOT NULL');
        $this->addSql('ALTER TABLE order_item_addons CHANGE price price NUMERIC(8, 2) NOT NULL');
        $this->addSql('ALTER TABLE payments CHANGE amount amount NUMERIC(8, 2) NOT NULL, CHANGE refunded_amount refunded_amount NUMERIC(8, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE restaurants CHANGE min_order_amount min_order_amount NUMERIC(8, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE riders CHANGE cash_on_hand cash_on_hand NUMERIC(8, 2) DEFAULT \'0.00\' NOT NULL, CHANGE cash_remit_limit cash_remit_limit NUMERIC(8, 2) DEFAULT \'2000.00\' NOT NULL');
        $this->addSql('ALTER TABLE rider_cash_remittances CHANGE amount amount NUMERIC(8, 2) NOT NULL');
        $this->addSql('ALTER TABLE rider_earnings CHANGE base_pay base_pay NUMERIC(6, 2) NOT NULL, CHANGE distance_pay distance_pay NUMERIC(6, 2) NOT NULL, CHANGE waiting_pay waiting_pay NUMERIC(6, 2) DEFAULT \'0.00\' NOT NULL, CHANGE incentive_pay incentive_pay NUMERIC(6, 2) DEFAULT \'0.00\' NOT NULL, CHANGE tip_amount tip_amount NUMERIC(6, 2) DEFAULT \'0.00\' NOT NULL, CHANGE total_earned total_earned NUMERIC(6, 2) NOT NULL');
        $this->addSql('ALTER TABLE rider_pool_offers CHANGE incentive_amount incentive_amount NUMERIC(6, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE vouchers CHANGE value value NUMERIC(8, 2) DEFAULT NULL, CHANGE min_order_amount min_order_amount NUMERIC(8, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE voucher_redemptions CHANGE discount_applied discount_applied NUMERIC(8, 2) NOT NULL');
    }
}
