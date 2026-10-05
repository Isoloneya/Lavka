<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005171345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cart, checkout, orders, stock reservations and stub payments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cart (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, user_id UUID DEFAULT NULL, currency VARCHAR(3) NOT NULL, coupon_code VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_cart_token ON cart (token_hash)');
        $this->addSql('CREATE INDEX IDX_BA388B7A76ED395 ON cart (user_id)');
        $this->addSql('CREATE TABLE cart_item (id UUID NOT NULL, cart_id UUID NOT NULL, variant_id UUID NOT NULL, quantity INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_cart_variant ON cart_item (cart_id, variant_id)');
        $this->addSql('CREATE INDEX IDX_F0FE25271AD5CDBF ON cart_item (cart_id)');
        $this->addSql('CREATE INDEX IDX_F0FE25273B69A9AF ON cart_item (variant_id)');
        $this->addSql('CREATE TABLE shop_order (id UUID NOT NULL, number VARCHAR(40) NOT NULL, cart_id UUID NOT NULL, user_id UUID DEFAULT NULL, email VARCHAR(254) NOT NULL, status VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, subtotal_minor BIGINT NOT NULL, discount_minor BIGINT NOT NULL, shipping_minor BIGINT NOT NULL, total_minor BIGINT NOT NULL, pricing_snapshot JSONB NOT NULL, shipping_address JSONB NOT NULL, shipping_method VARCHAR(30) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, placed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_order_number ON shop_order (number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_order_cart ON shop_order (cart_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_order_idempotency ON shop_order (idempotency_key)');
        $this->addSql('CREATE INDEX idx_order_expiry ON shop_order (status, expires_at)');
        $this->addSql('CREATE INDEX IDX_323FC9CAA76ED395 ON shop_order (user_id)');
        $this->addSql('CREATE TABLE order_item (id UUID NOT NULL, order_id UUID NOT NULL, variant_id UUID NOT NULL, sku VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, quantity INT NOT NULL, unit_price_minor BIGINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_52EA1F098D9F6D38 ON order_item (order_id)');
        $this->addSql('CREATE INDEX IDX_52EA1F093B69A9AF ON order_item (variant_id)');
        $this->addSql('CREATE TABLE stock_reservation (id UUID NOT NULL, order_id UUID NOT NULL, stock_item_id UUID NOT NULL, quantity INT NOT NULL, status VARCHAR(20) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_order_stock ON stock_reservation (order_id, stock_item_id)');
        $this->addSql('CREATE INDEX IDX_9D06EF618D9F6D38 ON stock_reservation (order_id)');
        $this->addSql('CREATE INDEX IDX_9D06EF61BC942FD ON stock_reservation (stock_item_id)');
        $this->addSql('CREATE TABLE order_status_history (id UUID NOT NULL, order_id UUID NOT NULL, from_status VARCHAR(20) DEFAULT NULL, to_status VARCHAR(20) NOT NULL, actor_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_471AD77E8D9F6D38 ON order_status_history (order_id)');
        $this->addSql('CREATE TABLE payment (id UUID NOT NULL, order_id UUID NOT NULL, provider VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_order ON payment (order_id)');
        $this->addSql('ALTER TABLE cart ADD CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT fk_cart_item_cart FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT fk_cart_item_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE shop_order ADD CONSTRAINT fk_order_cart FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE shop_order ADD CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT fk_order_item_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE stock_reservation ADD CONSTRAINT fk_reservation_order FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE stock_reservation ADD CONSTRAINT fk_reservation_stock FOREIGN KEY (stock_item_id) REFERENCES stock_item (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE order_status_history ADD CONSTRAINT fk_history_order FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT chk_cart_quantity CHECK (quantity BETWEEN 1 AND 100)');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT chk_order_item_values CHECK (quantity BETWEEN 1 AND 100 AND unit_price_minor >= 0)');
        $this->addSql('ALTER TABLE stock_reservation ADD CONSTRAINT chk_reservation_quantity CHECK (quantity > 0)');
        $this->addSql('ALTER TABLE shop_order ADD CONSTRAINT chk_order_totals CHECK (subtotal_minor >= 0 AND discount_minor >= 0 AND discount_minor <= subtotal_minor AND shipping_minor >= 0 AND total_minor = subtotal_minor - discount_minor + shipping_minor)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT chk_payment_amount CHECK (amount_minor >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart DROP CONSTRAINT fk_cart_user');
        $this->addSql('ALTER TABLE cart_item DROP CONSTRAINT fk_cart_item_cart');
        $this->addSql('ALTER TABLE cart_item DROP CONSTRAINT fk_cart_item_variant');
        $this->addSql('ALTER TABLE shop_order DROP CONSTRAINT fk_order_cart');
        $this->addSql('ALTER TABLE shop_order DROP CONSTRAINT fk_order_user');
        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT fk_order_item_order');
        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT fk_order_item_variant');
        $this->addSql('ALTER TABLE stock_reservation DROP CONSTRAINT fk_reservation_order');
        $this->addSql('ALTER TABLE stock_reservation DROP CONSTRAINT fk_reservation_stock');
        $this->addSql('ALTER TABLE order_status_history DROP CONSTRAINT fk_history_order');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT fk_payment_order');
        $this->addSql('DROP TABLE cart');
        $this->addSql('DROP TABLE cart_item');
        $this->addSql('DROP TABLE shop_order');
        $this->addSql('DROP TABLE order_item');
        $this->addSql('DROP TABLE stock_reservation');
        $this->addSql('DROP TABLE order_status_history');
        $this->addSql('DROP TABLE payment');
    }
}
