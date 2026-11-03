<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007070000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        // MariaDB DDL causes implicit commits, so this migration must not be
        // wrapped in a transaction by Doctrine Migrations.
        return false;
    }

    public function getDescription(): string
    {
        return 'Create the complete Foodjett application schema and required platform settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        // admins
        $this->addSql(<<<'SQL'
CREATE TABLE `admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admins_user_id_unique` (`user_id`),
  CONSTRAINT `admins_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // audit_logs
        $this->addSql(<<<'SQL'
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `subject_type` varchar(255) NOT NULL,
  `subject_id` bigint(20) unsigned NOT NULL,
  `changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`changes`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `audit_logs_user_id_foreign` (`user_id`),
  KEY `audit_logs_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // cache
        $this->addSql(<<<'SQL'
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // cache_locks
        $this->addSql(<<<'SQL'
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // conversations
        $this->addSql(<<<'SQL'
CREATE TABLE `conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `type` enum('customer_rider','customer_restaurant') NOT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversations_order_id_type_unique` (`order_id`,`type`),
  CONSTRAINT `conversations_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // customer_addresses
        $this->addSql(<<<'SQL'
CREATE TABLE `customer_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `address_line` varchar(255) NOT NULL,
  `landmark` varchar(255) DEFAULT NULL,
  `delivery_instructions` text DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_addresses_customer_id_foreign` (`customer_id`),
  CONSTRAINT `customer_addresses_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // customers
        $this->addSql(<<<'SQL'
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_user_id_unique` (`user_id`),
  CONSTRAINT `customers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // delivery_zones
        $this->addSql(<<<'SQL'
CREATE TABLE `delivery_zones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `polygon` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`polygon`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // failed_jobs
        $this->addSql(<<<'SQL'
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // job_batches
        $this->addSql(<<<'SQL'
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // jobs
        $this->addSql(<<<'SQL'
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // menu_categories
        $this->addSql(<<<'SQL'
CREATE TABLE `menu_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `restaurant_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `menu_categories_restaurant_id_foreign` (`restaurant_id`),
  CONSTRAINT `menu_categories_restaurant_id_foreign` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // menu_item_addons
        $this->addSql(<<<'SQL'
CREATE TABLE `menu_item_addons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `menu_item_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(8,2) NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `menu_item_addons_menu_item_id_foreign` (`menu_item_id`),
  CONSTRAINT `menu_item_addons_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // menu_item_variants
        $this->addSql(<<<'SQL'
CREATE TABLE `menu_item_variants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `menu_item_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `price_delta` decimal(8,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `menu_item_variants_menu_item_id_foreign` (`menu_item_id`),
  CONSTRAINT `menu_item_variants_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // menu_items
        $this->addSql(<<<'SQL'
CREATE TABLE `menu_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `menu_category_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `base_price` decimal(8,2) NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `available_from` time DEFAULT NULL,
  `available_until` time DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `menu_items_menu_category_id_foreign` (`menu_category_id`),
  KEY `menu_items_is_available_index` (`is_available`),
  CONSTRAINT `menu_items_menu_category_id_foreign` FOREIGN KEY (`menu_category_id`) REFERENCES `menu_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // messages
        $this->addSql(<<<'SQL'
CREATE TABLE `messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `sender_user_id` bigint(20) unsigned NOT NULL,
  `body` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `messages_sender_user_id_foreign` (`sender_user_id`),
  KEY `messages_conversation_id_created_at_index` (`conversation_id`,`created_at`),
  CONSTRAINT `messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_sender_user_id_foreign` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // notifications
        $this->addSql(<<<'SQL'
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_user_id_foreign` (`user_id`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // order_item_addons
        $this->addSql(<<<'SQL'
CREATE TABLE `order_item_addons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` bigint(20) unsigned NOT NULL,
  `menu_item_addon_id` bigint(20) unsigned NOT NULL,
  `price` decimal(8,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_item_addons_order_item_id_foreign` (`order_item_id`),
  KEY `order_item_addons_menu_item_addon_id_foreign` (`menu_item_addon_id`),
  CONSTRAINT `order_item_addons_menu_item_addon_id_foreign` FOREIGN KEY (`menu_item_addon_id`) REFERENCES `menu_item_addons` (`id`),
  CONSTRAINT `order_item_addons_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // order_items
        $this->addSql(<<<'SQL'
CREATE TABLE `order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `menu_item_id` bigint(20) unsigned NOT NULL,
  `menu_item_variant_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `unit_price` decimal(8,2) NOT NULL,
  `special_instructions` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_items_order_id_foreign` (`order_id`),
  KEY `order_items_menu_item_id_foreign` (`menu_item_id`),
  KEY `order_items_menu_item_variant_id_foreign` (`menu_item_variant_id`),
  CONSTRAINT `order_items_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`),
  CONSTRAINT `order_items_menu_item_variant_id_foreign` FOREIGN KEY (`menu_item_variant_id`) REFERENCES `menu_item_variants` (`id`),
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // order_reports
        $this->addSql(<<<'SQL'
CREATE TABLE `order_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `reported_by_user_id` bigint(20) unsigned NOT NULL,
  `against` enum('restaurant','rider','customer','platform') NOT NULL,
  `type` enum('missing_item','wrong_item','late_delivery','no_show_rider','rude_behavior','customer_unreachable','accident','other') NOT NULL,
  `description` text NOT NULL,
  `status` enum('open','under_review','resolved','rejected') NOT NULL DEFAULT 'open',
  `resolution` text DEFAULT NULL,
  `resolved_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_reports_order_id_foreign` (`order_id`),
  KEY `order_reports_reported_by_user_id_foreign` (`reported_by_user_id`),
  KEY `order_reports_resolved_by_admin_id_foreign` (`resolved_by_admin_id`),
  CONSTRAINT `order_reports_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_reports_reported_by_user_id_foreign` FOREIGN KEY (`reported_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_reports_resolved_by_admin_id_foreign` FOREIGN KEY (`resolved_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // order_status_history
        $this->addSql(<<<'SQL'
CREATE TABLE `order_status_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL,
  `changed_by` enum('customer','restaurant','rider','admin','system') NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_status_history_order_id_foreign` (`order_id`),
  CONSTRAINT `order_status_history_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // orders
        $this->addSql(<<<'SQL'
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(255) NOT NULL,
  `checkout_token` char(36) DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `restaurant_id` bigint(20) unsigned NOT NULL,
  `customer_address_id` bigint(20) unsigned NOT NULL,
  `rider_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('placed','accepted','preparing','ready','finding_rider','rider_assigned','at_restaurant','picked_up','on_the_way','arrived','delivered','rejected_by_restaurant','cancelled_by_customer','cancelled_by_restaurant','cancelled_no_rider','cancelled_by_admin','failed_delivery') NOT NULL DEFAULT 'placed',
  `subtotal` decimal(8,2) NOT NULL,
  `delivery_fee` decimal(8,2) NOT NULL,
  `service_fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `tip_amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(8,2) NOT NULL,
  `commission_amount` decimal(8,2) DEFAULT NULL,
  `payment_method` enum('cod','gcash','card') NOT NULL,
  `customer_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_by` enum('customer','restaurant','rider','admin','system') DEFAULT NULL,
  `placed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `accepted_at` timestamp NULL DEFAULT NULL,
  `estimated_prep_minutes` int(10) unsigned DEFAULT NULL,
  `estimated_ready_at` timestamp NULL DEFAULT NULL,
  `prep_extended_minutes` int(10) unsigned DEFAULT NULL,
  `ready_at` timestamp NULL DEFAULT NULL,
  `rider_search_started_at` timestamp NULL DEFAULT NULL,
  `rider_assigned_at` timestamp NULL DEFAULT NULL,
  `rider_arrived_restaurant_at` timestamp NULL DEFAULT NULL,
  `picked_up_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `pickup_code` varchar(255) DEFAULT NULL,
  `proof_of_delivery_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_order_number_unique` (`order_number`),
  UNIQUE KEY `orders_checkout_token_unique` (`checkout_token`),
  KEY `orders_customer_id_foreign` (`customer_id`),
  KEY `orders_restaurant_id_foreign` (`restaurant_id`),
  KEY `orders_customer_address_id_foreign` (`customer_address_id`),
  KEY `orders_rider_id_foreign` (`rider_id`),
  KEY `orders_status_index` (`status`),
  CONSTRAINT `orders_customer_address_id_foreign` FOREIGN KEY (`customer_address_id`) REFERENCES `customer_addresses` (`id`),
  CONSTRAINT `orders_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `orders_restaurant_id_foreign` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `orders_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // passkeys
        $this->addSql(<<<'SQL'
CREATE TABLE `passkeys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `credential` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`credential`)),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
  KEY `passkeys_user_id_index` (`user_id`),
  CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // password_reset_tokens
        $this->addSql(<<<'SQL'
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // payment_status_history
        $this->addSql(<<<'SQL'
CREATE TABLE `payment_status_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `from_status` enum('pending','paid','refunded','partially_refunded','failed') DEFAULT NULL,
  `to_status` enum('pending','paid','refunded','partially_refunded','failed') NOT NULL,
  `changed_by` enum('customer','rider','admin','system') NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `payment_status_history_payment_id_foreign` (`payment_id`),
  CONSTRAINT `payment_status_history_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // payments
        $this->addSql(<<<'SQL'
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `method` enum('cod','gcash','card') NOT NULL,
  `status` enum('pending','paid','refunded','partially_refunded','failed') NOT NULL DEFAULT 'pending',
  `amount` decimal(8,2) NOT NULL,
  `refunded_amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `transaction_reference` varchar(255) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_order_id_unique` (`order_id`),
  CONSTRAINT `payments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // pending_checkouts
        $this->addSql(<<<'SQL'
CREATE TABLE `pending_checkouts` (
  `id` char(36) NOT NULL,
  `idempotency_token` char(36) DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `restaurant_id` bigint(20) unsigned NOT NULL,
  `customer_address_id` bigint(20) unsigned NOT NULL,
  `payment_method` enum('gcash','card') NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `total_amount` decimal(8,2) NOT NULL,
  `paymongo_session_id` varchar(255) DEFAULT NULL,
  `paymongo_checkout_url` text DEFAULT NULL,
  `paymongo_reference` varchar(255) NOT NULL,
  `status` enum('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pending_checkouts_paymongo_reference_unique` (`paymongo_reference`),
  UNIQUE KEY `pending_checkouts_paymongo_session_id_unique` (`paymongo_session_id`),
  UNIQUE KEY `pending_checkouts_order_id_unique` (`order_id`),
  UNIQUE KEY `pending_checkouts_idempotency_token_unique` (`idempotency_token`),
  KEY `pending_checkouts_restaurant_id_foreign` (`restaurant_id`),
  KEY `pending_checkouts_customer_address_id_foreign` (`customer_address_id`),
  KEY `pending_checkouts_customer_id_status_index` (`customer_id`,`status`),
  CONSTRAINT `pending_checkouts_customer_address_id_foreign` FOREIGN KEY (`customer_address_id`) REFERENCES `customer_addresses` (`id`),
  CONSTRAINT `pending_checkouts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pending_checkouts_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pending_checkouts_restaurant_id_foreign` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // platform_settings
        $this->addSql(<<<'SQL'
CREATE TABLE `platform_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `platform_settings_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // restaurant_documents
        $this->addSql(<<<'SQL'
CREATE TABLE `restaurant_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `restaurant_id` bigint(20) unsigned NOT NULL,
  `type` enum('business_permit','food_safety_permit','owner_id') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `restaurant_documents_restaurant_id_foreign` (`restaurant_id`),
  CONSTRAINT `restaurant_documents_restaurant_id_foreign` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // restaurant_operating_hours
        $this->addSql(<<<'SQL'
CREATE TABLE `restaurant_operating_hours` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `restaurant_id` bigint(20) unsigned NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `opens_at` time NOT NULL,
  `closes_at` time NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `restaurant_operating_hours_restaurant_id_foreign` (`restaurant_id`),
  CONSTRAINT `restaurant_operating_hours_restaurant_id_foreign` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // restaurant_payouts
        $this->addSql(<<<'SQL'
CREATE TABLE `restaurant_payouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `restaurant_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `gross_sales` decimal(10,2) NOT NULL,
  `commission_deducted` decimal(10,2) NOT NULL,
  `net_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `restaurant_payout_period_unique` (`restaurant_id`,`period_start`,`period_end`),
  CONSTRAINT `restaurant_payouts_restaurant_id_foreign` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // restaurant_reviews
        $this->addSql(<<<'SQL'
CREATE TABLE `restaurant_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `restaurant_id` bigint(20) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `comment` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `restaurant_reply` text DEFAULT NULL,
  `replied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `restaurant_reviews_order_id_unique` (`order_id`),
  KEY `restaurant_reviews_customer_id_foreign` (`customer_id`),
  KEY `restaurant_reviews_restaurant_id_foreign` (`restaurant_id`),
  CONSTRAINT `restaurant_reviews_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `restaurant_reviews_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `restaurant_reviews_restaurant_id_foreign` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // restaurants
        $this->addSql(<<<'SQL'
CREATE TABLE `restaurants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `cover_photo_path` varchar(255) DEFAULT NULL,
  `cuisine_type` varchar(255) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `default_prep_time_minutes` int(10) unsigned NOT NULL DEFAULT 15,
  `min_order_amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 15.00,
  `approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `operating_status` enum('open','closed','temporarily_closed') NOT NULL DEFAULT 'closed',
  `payout_method` enum('bank','ewallet') DEFAULT NULL,
  `payout_account_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payout_account_details`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `restaurants_user_id_unique` (`user_id`),
  UNIQUE KEY `restaurants_slug_unique` (`slug`),
  KEY `restaurants_approval_status_operating_status_index` (`approval_status`,`operating_status`),
  CONSTRAINT `restaurants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // rider_cash_remittances
        $this->addSql(<<<'SQL'
CREATE TABLE `rider_cash_remittances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rider_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(8,2) NOT NULL,
  `reference_note` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed') NOT NULL DEFAULT 'pending',
  `confirmed_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `remitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rider_cash_remittances_rider_id_foreign` (`rider_id`),
  KEY `rider_cash_remittances_confirmed_by_admin_id_foreign` (`confirmed_by_admin_id`),
  CONSTRAINT `rider_cash_remittances_confirmed_by_admin_id_foreign` FOREIGN KEY (`confirmed_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rider_cash_remittances_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // rider_documents
        $this->addSql(<<<'SQL'
CREATE TABLE `rider_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rider_id` bigint(20) unsigned NOT NULL,
  `type` enum('drivers_license','vehicle_registration','valid_id','police_clearance') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rider_documents_rider_id_foreign` (`rider_id`),
  CONSTRAINT `rider_documents_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // rider_earnings
        $this->addSql(<<<'SQL'
CREATE TABLE `rider_earnings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rider_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `base_pay` decimal(6,2) NOT NULL,
  `distance_pay` decimal(6,2) NOT NULL,
  `waiting_pay` decimal(6,2) NOT NULL DEFAULT 0.00,
  `incentive_pay` decimal(6,2) NOT NULL DEFAULT 0.00,
  `tip_amount` decimal(6,2) NOT NULL DEFAULT 0.00,
  `total_earned` decimal(6,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rider_earnings_order_id_unique` (`order_id`),
  KEY `rider_earnings_rider_id_foreign` (`rider_id`),
  CONSTRAINT `rider_earnings_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rider_earnings_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // rider_payouts
        $this->addSql(<<<'SQL'
CREATE TABLE `rider_payouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rider_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rider_payout_period_unique` (`rider_id`,`period_start`,`period_end`),
  CONSTRAINT `rider_payouts_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // rider_pool_declines
        $this->addSql(<<<'SQL'
CREATE TABLE `rider_pool_declines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `rider_id` bigint(20) unsigned NOT NULL,
  `action` enum('skipped','expired') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `rider_pool_declines_order_id_foreign` (`order_id`),
  KEY `rider_pool_declines_rider_id_foreign` (`rider_id`),
  CONSTRAINT `rider_pool_declines_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rider_pool_declines_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // rider_pool_offers
        $this->addSql(<<<'SQL'
CREATE TABLE `rider_pool_offers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `search_radius_km` decimal(4,1) NOT NULL DEFAULT 3.0,
  `incentive_amount` decimal(6,2) NOT NULL DEFAULT 0.00,
  `escalation_stage` enum('initial','widened','incentivized','admin_alerted','customer_notified','auto_cancelled') NOT NULL DEFAULT 'initial',
  `admin_assigned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rider_pool_offers_order_id_unique` (`order_id`),
  CONSTRAINT `rider_pool_offers_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // rider_reviews
        $this->addSql(<<<'SQL'
CREATE TABLE `rider_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `rider_id` bigint(20) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rider_reviews_order_id_unique` (`order_id`),
  KEY `rider_reviews_customer_id_foreign` (`customer_id`),
  KEY `rider_reviews_rider_id_foreign` (`rider_id`),
  CONSTRAINT `rider_reviews_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rider_reviews_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rider_reviews_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // riders
        $this->addSql(<<<'SQL'
CREATE TABLE `riders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `vehicle_type` enum('motorcycle','bicycle','car') NOT NULL,
  `plate_number` varchar(255) DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `availability_status` enum('offline','available','busy') NOT NULL DEFAULT 'offline',
  `current_latitude` decimal(10,7) DEFAULT NULL,
  `current_longitude` decimal(10,7) DEFAULT NULL,
  `last_location_at` timestamp NULL DEFAULT NULL,
  `cash_on_hand` decimal(8,2) NOT NULL DEFAULT 0.00,
  `cash_remit_limit` decimal(8,2) NOT NULL DEFAULT 2000.00,
  `payout_method` enum('bank','ewallet') DEFAULT NULL,
  `payout_account_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payout_account_details`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `riders_user_id_unique` (`user_id`),
  KEY `riders_availability_status_approval_status_index` (`availability_status`,`approval_status`),
  CONSTRAINT `riders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // sessions
        $this->addSql(<<<'SQL'
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // support_tickets
        $this->addSql(<<<'SQL'
CREATE TABLE `support_tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('open','in_progress','closed') NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_tickets_user_id_foreign` (`user_id`),
  CONSTRAINT `support_tickets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // users
        $this->addSql(<<<'SQL'
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `role` enum('admin','restaurant','rider','customer') NOT NULL,
  `status` enum('active','suspended','banned') NOT NULL DEFAULT 'active',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_phone_unique` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // voucher_redemptions
        $this->addSql(<<<'SQL'
CREATE TABLE `voucher_redemptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `voucher_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `discount_applied` decimal(8,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_redemptions_order_id_unique` (`order_id`),
  KEY `voucher_redemptions_voucher_id_foreign` (`voucher_id`),
  KEY `voucher_redemptions_customer_id_foreign` (`customer_id`),
  CONSTRAINT `voucher_redemptions_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `voucher_redemptions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `voucher_redemptions_voucher_id_foreign` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // vouchers
        $this->addSql(<<<'SQL'
CREATE TABLE `vouchers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `scope` enum('platform','restaurant') NOT NULL,
  `restaurant_id` bigint(20) unsigned DEFAULT NULL,
  `type` enum('percentage','fixed','free_delivery') NOT NULL,
  `value` decimal(8,2) DEFAULT NULL,
  `min_order_amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `usage_limit_total` int(10) unsigned DEFAULT NULL,
  `usage_limit_per_customer` int(10) unsigned NOT NULL DEFAULT 1,
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vouchers_code_unique` (`code`),
  KEY `vouchers_restaurant_id_foreign` (`restaurant_id`),
  CONSTRAINT `vouchers_restaurant_id_foreign` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // Operational defaults originally seeded by the Laravel schema migrations.
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (1,'rider_search_initial_radius_km','3','Initial search radius in kilometres when looking for a rider','2026-10-06 22:51:24','2026-10-06 22:51:24');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (2,'rider_search_widen_radius_km','8','Widened rider search radius in kilometres after escalation','2026-10-06 22:51:24','2026-10-06 22:51:24');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (3,'rider_search_widen_after_minutes','2','Minutes before widening the rider search radius','2026-10-06 22:51:24','2026-10-06 22:51:24');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (4,'rider_incentive_after_minutes','4','Minutes before adding an incentive to the rider offer','2026-10-06 22:51:24','2026-10-06 22:51:24');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (5,'admin_alert_after_minutes','6','Minutes before alerting an administrator about an unassigned order','2026-10-06 22:51:24','2026-10-06 22:51:24');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (6,'customer_notify_after_minutes','8','Minutes before notifying the customer about a rider-search delay','2026-10-06 22:51:24','2026-10-06 22:51:24');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (7,'auto_cancel_after_minutes','15','Minutes before automatically cancelling an order when no rider is found','2026-10-06 22:51:24','2026-10-06 22:51:24');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (8,'default_commission_rate','15','Default commission percentage assigned to newly registered restaurants','2026-10-06 22:51:24','2026-10-06 22:51:24');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (9,'rider_waiting_compensation_threshold_minutes','5','Restaurant waiting minutes before rider compensation begins','2026-10-06 22:51:24','2026-10-06 22:51:24');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (10,'delivery_base_fee','35','Base delivery fee charged on every order','2026-10-06 22:51:25','2026-10-06 22:51:25');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (11,'delivery_fee_per_km','10','Additional delivery fee charged per kilometre','2026-10-06 22:51:25','2026-10-06 22:51:25');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (12,'chat_close_after_minutes','30','Minutes that order conversations remain writable after an order finishes','2026-10-06 22:51:25','2026-10-06 22:51:25');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (13,'rider_waiting_compensation_amount','10','Fixed rider waiting pay once the restaurant waiting threshold is reached','2026-10-06 22:51:25','2026-10-06 22:51:25');
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO `platform_settings` (`id`, `key`, `value`, `description`, `created_at`, `updated_at`) VALUES (14,'rider_customer_unreachable_wait_minutes','5','Minutes a rider must wait after arrival before reporting the customer unreachable','2026-10-06 22:51:25','2026-10-06 22:51:25');
SQL);

        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql('DROP TABLE IF EXISTS `vouchers`');
        $this->addSql('DROP TABLE IF EXISTS `voucher_redemptions`');
        $this->addSql('DROP TABLE IF EXISTS `users`');
        $this->addSql('DROP TABLE IF EXISTS `support_tickets`');
        $this->addSql('DROP TABLE IF EXISTS `sessions`');
        $this->addSql('DROP TABLE IF EXISTS `riders`');
        $this->addSql('DROP TABLE IF EXISTS `rider_reviews`');
        $this->addSql('DROP TABLE IF EXISTS `rider_pool_offers`');
        $this->addSql('DROP TABLE IF EXISTS `rider_pool_declines`');
        $this->addSql('DROP TABLE IF EXISTS `rider_payouts`');
        $this->addSql('DROP TABLE IF EXISTS `rider_earnings`');
        $this->addSql('DROP TABLE IF EXISTS `rider_documents`');
        $this->addSql('DROP TABLE IF EXISTS `rider_cash_remittances`');
        $this->addSql('DROP TABLE IF EXISTS `restaurants`');
        $this->addSql('DROP TABLE IF EXISTS `restaurant_reviews`');
        $this->addSql('DROP TABLE IF EXISTS `restaurant_payouts`');
        $this->addSql('DROP TABLE IF EXISTS `restaurant_operating_hours`');
        $this->addSql('DROP TABLE IF EXISTS `restaurant_documents`');
        $this->addSql('DROP TABLE IF EXISTS `platform_settings`');
        $this->addSql('DROP TABLE IF EXISTS `pending_checkouts`');
        $this->addSql('DROP TABLE IF EXISTS `payments`');
        $this->addSql('DROP TABLE IF EXISTS `payment_status_history`');
        $this->addSql('DROP TABLE IF EXISTS `password_reset_tokens`');
        $this->addSql('DROP TABLE IF EXISTS `passkeys`');
        $this->addSql('DROP TABLE IF EXISTS `orders`');
        $this->addSql('DROP TABLE IF EXISTS `order_status_history`');
        $this->addSql('DROP TABLE IF EXISTS `order_reports`');
        $this->addSql('DROP TABLE IF EXISTS `order_items`');
        $this->addSql('DROP TABLE IF EXISTS `order_item_addons`');
        $this->addSql('DROP TABLE IF EXISTS `notifications`');
        $this->addSql('DROP TABLE IF EXISTS `messages`');
        $this->addSql('DROP TABLE IF EXISTS `menu_items`');
        $this->addSql('DROP TABLE IF EXISTS `menu_item_variants`');
        $this->addSql('DROP TABLE IF EXISTS `menu_item_addons`');
        $this->addSql('DROP TABLE IF EXISTS `menu_categories`');
        $this->addSql('DROP TABLE IF EXISTS `jobs`');
        $this->addSql('DROP TABLE IF EXISTS `job_batches`');
        $this->addSql('DROP TABLE IF EXISTS `failed_jobs`');
        $this->addSql('DROP TABLE IF EXISTS `delivery_zones`');
        $this->addSql('DROP TABLE IF EXISTS `customers`');
        $this->addSql('DROP TABLE IF EXISTS `customer_addresses`');
        $this->addSql('DROP TABLE IF EXISTS `conversations`');
        $this->addSql('DROP TABLE IF EXISTS `cache_locks`');
        $this->addSql('DROP TABLE IF EXISTS `cache`');
        $this->addSql('DROP TABLE IF EXISTS `audit_logs`');
        $this->addSql('DROP TABLE IF EXISTS `admins`');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
