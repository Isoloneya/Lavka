<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002200055 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Користувачі, прайс-листи, правила знижок, склади та аудит';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(254) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL, customer_group VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE TABLE price_list (id UUID NOT NULL, code VARCHAR(64) NOT NULL, currency VARCHAR(3) NOT NULL, customer_group VARCHAR(64) DEFAULT NULL, valid_from TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, valid_to TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, priority INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_price_list_code ON price_list (code)');
        $this->addSql('CREATE TABLE price (id UUID NOT NULL, price_list_id UUID NOT NULL, variant_id UUID NOT NULL, amount_minor BIGINT NOT NULL, min_quantity INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_price_tier ON price (price_list_id, variant_id, min_quantity)');
        $this->addSql('CREATE INDEX IDX_CAC822D95688DED7 ON price (price_list_id)');
        $this->addSql('CREATE INDEX IDX_CAC822D93B69A9AF ON price (variant_id)');
        $this->addSql('CREATE TABLE promotion_rule (id UUID NOT NULL, name VARCHAR(255) NOT NULL, scope VARCHAR(10) NOT NULL, priority INT NOT NULL, conditions JSONB NOT NULL, actions JSONB NOT NULL, stop_processing BOOLEAN NOT NULL, coupon_code VARCHAR(64) DEFAULT NULL, valid_from TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, valid_to TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_promotion_coupon ON promotion_rule (coupon_code)');
        $this->addSql('CREATE TABLE warehouse (id UUID NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_warehouse_code ON warehouse (code)');
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, actor_id UUID NOT NULL, action VARCHAR(10) NOT NULL, resource VARCHAR(255) NOT NULL, changes JSONB NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_created_at ON audit_log (created_at)');
        $this->addSql('ALTER TABLE price ADD CONSTRAINT fk_price_list FOREIGN KEY (price_list_id) REFERENCES price_list (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE price ADD CONSTRAINT fk_price_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE RESTRICT');
        $this->addSql("INSERT INTO warehouse (id, code, name) SELECT DISTINCT warehouse_id, 'legacy-' || warehouse_id::text, 'Імпортований склад' FROM stock_item");
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT fk_stock_item_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_6017DDA5080ECDE ON stock_item (warehouse_id)');
        $this->addSql('ALTER TABLE price ADD CONSTRAINT chk_price_amount CHECK (amount_minor >= 0)');
        $this->addSql('ALTER TABLE price ADD CONSTRAINT chk_price_quantity CHECK (min_quantity BETWEEN 1 AND 100)');
        $this->addSql('ALTER TABLE price_list ADD CONSTRAINT chk_price_list_period CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to > valid_from)');
        $this->addSql('ALTER TABLE promotion_rule ADD CONSTRAINT chk_promotion_period CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to > valid_from)');
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT chk_user_role CHECK (role IN ('ROLE_CUSTOMER', 'ROLE_MANAGER', 'ROLE_ADMIN'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item DROP CONSTRAINT fk_stock_item_warehouse');
        $this->addSql('DROP INDEX IDX_6017DDA5080ECDE');
        $this->addSql('ALTER TABLE price DROP CONSTRAINT fk_price_list');
        $this->addSql('ALTER TABLE price DROP CONSTRAINT fk_price_variant');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE price_list');
        $this->addSql('DROP TABLE price');
        $this->addSql('DROP TABLE promotion_rule');
        $this->addSql('DROP TABLE warehouse');
        $this->addSql('DROP TABLE audit_log');
    }
}
