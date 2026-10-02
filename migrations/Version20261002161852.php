<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002161852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Створення каталогу та залишків із JSONB і обмеженнями цілісності';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE category (parent_id UUID DEFAULT NULL, slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, attribute_schema JSONB NOT NULL, is_active BOOLEAN NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64C19C1989D9B62 ON category (slug)');
        $this->addSql('CREATE INDEX IDX_64C19C1727ACA70 ON category (parent_id)');

        $this->addSql('CREATE TABLE product (category_id UUID NOT NULL, slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, attributes JSONB NOT NULL, status VARCHAR(20) NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D34A04AD989D9B62 ON product (slug)');
        $this->addSql('CREATE INDEX IDX_D34A04AD12469DE2 ON product (category_id)');
        $this->addSql('CREATE INDEX IDX_D34A04AD7B00651C ON product (status)');

        $this->addSql('CREATE TABLE product_variant (product_id UUID NOT NULL, sku VARCHAR(64) NOT NULL, options JSONB NOT NULL, is_active BOOLEAN NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_209AA41DF9038C4 ON product_variant (sku)');
        $this->addSql('CREATE INDEX IDX_209AA41D4584665A ON product_variant (product_id)');

        $this->addSql('CREATE TABLE stock_item (variant_id UUID NOT NULL, warehouse_id UUID NOT NULL, quantity INT NOT NULL, reserved INT NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_item_variant_warehouse ON stock_item (variant_id, warehouse_id)');

        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT chk_stock_item_quantity_non_negative CHECK (quantity >= 0)');
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT chk_stock_item_reserved_non_negative CHECK (reserved >= 0)');
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT chk_stock_item_reserved_within_quantity CHECK (reserved <= quantity)');

        $this->addSql('ALTER TABLE category ADD CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT fk_product_variant_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT fk_stock_item_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stock_item');
        $this->addSql('DROP TABLE product_variant');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE category');
    }
}
