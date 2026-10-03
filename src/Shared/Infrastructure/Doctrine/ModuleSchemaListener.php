<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema, priority: 20)]
final class ModuleSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();

        $group = $schema->createTable('customer_group');
        $group->addColumn('id', 'guid');
        $group->addColumn('code', 'string', ['length' => 64]);
        $group->addColumn('name', 'string', ['length' => 255]);
        $group->setPrimaryKey(['id']);
        $group->addUniqueIndex(['code'], 'uniq_customer_group_code');
        if ($schema->hasTable('app_user')) {
            $schema->getTable('app_user')->addForeignKeyConstraint('customer_group', ['customer_group'], ['code'], ['onDelete' => 'RESTRICT'], 'fk_user_customer_group');
        }

        $list = $schema->createTable('price_list');
        $list->addColumn('id', 'guid');
        $list->addColumn('code', 'string', ['length' => 64]);
        $list->addColumn('currency', 'string', ['length' => 3]);
        $list->addColumn('customer_group', 'string', ['length' => 64, 'notnull' => false]);
        $list->addColumn('valid_from', 'datetimetz_immutable', ['notnull' => false]);
        $list->addColumn('valid_to', 'datetimetz_immutable', ['notnull' => false]);
        $list->addColumn('priority', 'integer');
        $list->setPrimaryKey(['id']);
        $list->addUniqueIndex(['code'], 'uniq_price_list_code');
        $list->addForeignKeyConstraint('customer_group', ['customer_group'], ['code'], ['onDelete' => 'RESTRICT'], 'fk_price_list_customer_group');

        $price = $schema->createTable('price');
        $price->addColumn('id', 'guid');
        $price->addColumn('price_list_id', 'guid');
        $price->addColumn('variant_id', 'guid');
        $price->addColumn('amount_minor', 'bigint');
        $price->addColumn('min_quantity', 'integer');
        $price->setPrimaryKey(['id']);
        $price->addUniqueIndex(['price_list_id', 'variant_id', 'min_quantity'], 'uniq_price_tier');
        $price->addForeignKeyConstraint('price_list', ['price_list_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_price_list');
        $price->addForeignKeyConstraint('product_variant', ['variant_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_price_variant');

        $rule = $schema->createTable('promotion_rule');
        $rule->addColumn('id', 'guid');
        $rule->addColumn('name', 'string', ['length' => 255]);
        $rule->addColumn('scope', 'string', ['length' => 10]);
        $rule->addColumn('priority', 'integer');
        $rule->addColumn('conditions', 'json', ['platformOptions' => ['jsonb' => true]]);
        $rule->addColumn('actions', 'json', ['platformOptions' => ['jsonb' => true]]);
        $rule->addColumn('stop_processing', 'boolean');
        $rule->addColumn('coupon_code', 'string', ['length' => 64, 'notnull' => false]);
        $rule->addColumn('valid_from', 'datetimetz_immutable', ['notnull' => false]);
        $rule->addColumn('valid_to', 'datetimetz_immutable', ['notnull' => false]);
        $rule->addColumn('is_active', 'boolean');
        $rule->setPrimaryKey(['id']);
        $rule->addUniqueIndex(['coupon_code'], 'uniq_promotion_coupon');

        $warehouse = $schema->createTable('warehouse');
        $warehouse->addColumn('id', 'guid');
        $warehouse->addColumn('code', 'string', ['length' => 64]);
        $warehouse->addColumn('name', 'string', ['length' => 255]);
        $warehouse->setPrimaryKey(['id']);
        $warehouse->addUniqueIndex(['code'], 'uniq_warehouse_code');

        if ($schema->hasTable('stock_item')) {
            $schema->getTable('stock_item')->addForeignKeyConstraint('warehouse', ['warehouse_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_stock_item_warehouse');
        }

        $audit = $schema->createTable('audit_log');
        $audit->addColumn('id', 'guid');
        $audit->addColumn('actor_id', 'guid');
        $audit->addColumn('action', 'string', ['length' => 10]);
        $audit->addColumn('resource', 'string', ['length' => 255]);
        $audit->addColumn('changes', 'json', ['platformOptions' => ['jsonb' => true]]);
        $audit->addColumn('created_at', 'datetimetz_immutable');
        $audit->setPrimaryKey(['id']);
        $audit->addIndex(['created_at'], 'idx_audit_created_at');
    }
}
