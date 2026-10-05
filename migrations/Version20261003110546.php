<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003110546 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Групи покупців та дата створення товару';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_group (id UUID NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_group_code ON customer_group (code)');
        $this->addSql('INSERT INTO customer_group (id, code, name) SELECT gen_random_uuid(), code, code FROM (SELECT customer_group AS code FROM app_user WHERE customer_group IS NOT NULL UNION SELECT customer_group AS code FROM price_list WHERE customer_group IS NOT NULL) existing_groups');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT fk_user_customer_group FOREIGN KEY (customer_group) REFERENCES customer_group (code) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_88BDF3E9A3F531FE ON app_user (customer_group)');
        $this->addSql('ALTER TABLE price_list ADD CONSTRAINT fk_price_list_customer_group FOREIGN KEY (customer_group) REFERENCES customer_group (code) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_399A0AA2A3F531FE ON price_list (customer_group)');
        $this->addSql('ALTER TABLE product ADD created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP created_at');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT fk_user_customer_group');
        $this->addSql('DROP INDEX IDX_88BDF3E9A3F531FE');
        $this->addSql('ALTER TABLE price_list DROP CONSTRAINT fk_price_list_customer_group');
        $this->addSql('DROP INDEX IDX_399A0AA2A3F531FE');
        $this->addSql('DROP TABLE customer_group');
    }
}
