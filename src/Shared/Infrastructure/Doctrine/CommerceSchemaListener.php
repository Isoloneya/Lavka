<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema, priority: 10)]
final class CommerceSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        $cart = $schema->createTable('cart');
        $cart->addColumn('id', 'guid');
        $cart->addColumn('token_hash', 'string', ['length' => 64]);
        $cart->addColumn('user_id', 'guid', ['notnull' => false]);
        $cart->addColumn('currency', 'string', ['length' => 3]);
        $cart->addColumn('coupon_code', 'string', ['length' => 64, 'notnull' => false]);
        $cart->addColumn('status', 'string', ['length' => 20]);
        $cart->addColumn('updated_at', 'datetimetz_immutable');
        $cart->setPrimaryKey(['id']);
        $cart->addUniqueIndex(['token_hash'], 'uniq_cart_token');
        $cart->addForeignKeyConstraint('app_user', ['user_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cart_user');

        $item = $schema->createTable('cart_item');
        $item->addColumn('id', 'guid');
        $item->addColumn('cart_id', 'guid');
        $item->addColumn('variant_id', 'guid');
        $item->addColumn('quantity', 'integer');
        $item->setPrimaryKey(['id']);
        $item->addUniqueIndex(['cart_id', 'variant_id'], 'uniq_cart_variant');
        $item->addForeignKeyConstraint('cart', ['cart_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_cart_item_cart');
        $item->addForeignKeyConstraint('product_variant', ['variant_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cart_item_variant');

        $order = $schema->createTable('shop_order');
        $order->addColumn('id', 'guid');
        $order->addColumn('number', 'string', ['length' => 40]);
        $order->addColumn('cart_id', 'guid');
        $order->addColumn('user_id', 'guid', ['notnull' => false]);
        $order->addColumn('email', 'string', ['length' => 254]);
        $order->addColumn('status', 'string', ['length' => 20]);
        $order->addColumn('currency', 'string', ['length' => 3]);
        foreach (['subtotal_minor', 'discount_minor', 'shipping_minor', 'total_minor'] as $name) {
            $order->addColumn($name, 'bigint');
        }
        foreach (['pricing_snapshot', 'shipping_address'] as $name) {
            $order->addColumn($name, 'json', ['platformOptions' => ['jsonb' => true]]);
        }
        $order->addColumn('shipping_method', 'string', ['length' => 30]);
        $order->addColumn('idempotency_key', 'string', ['length' => 128]);
        $order->addColumn('request_hash', 'string', ['length' => 64]);
        $order->addColumn('placed_at', 'datetimetz_immutable');
        $order->addColumn('expires_at', 'datetimetz_immutable');
        $order->setPrimaryKey(['id']);
        $order->addUniqueIndex(['number'], 'uniq_order_number');
        $order->addUniqueIndex(['cart_id'], 'uniq_order_cart');
        $order->addUniqueIndex(['idempotency_key'], 'uniq_order_idempotency');
        $order->addIndex(['status', 'expires_at'], 'idx_order_expiry');
        $order->addForeignKeyConstraint('cart', ['cart_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_order_cart');
        $order->addForeignKeyConstraint('app_user', ['user_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_order_user');

        $line = $schema->createTable('order_item');
        $line->addColumn('id', 'guid');
        $line->addColumn('order_id', 'guid');
        $line->addColumn('variant_id', 'guid');
        $line->addColumn('sku', 'string', ['length' => 64]);
        $line->addColumn('name', 'string', ['length' => 255]);
        $line->addColumn('quantity', 'integer');
        $line->addColumn('unit_price_minor', 'bigint');
        $line->setPrimaryKey(['id']);
        $line->addForeignKeyConstraint('shop_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_order_item_order');
        $line->addForeignKeyConstraint('product_variant', ['variant_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_order_item_variant');

        $reservation = $schema->createTable('stock_reservation');
        $reservation->addColumn('id', 'guid');
        $reservation->addColumn('order_id', 'guid');
        $reservation->addColumn('stock_item_id', 'guid');
        $reservation->addColumn('quantity', 'integer');
        $reservation->addColumn('status', 'string', ['length' => 20]);
        $reservation->setPrimaryKey(['id']);
        $reservation->addUniqueIndex(['order_id', 'stock_item_id'], 'uniq_reservation_order_stock');
        $reservation->addForeignKeyConstraint('shop_order', ['order_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_reservation_order');
        $reservation->addForeignKeyConstraint('stock_item', ['stock_item_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_reservation_stock');

        $history = $schema->createTable('order_status_history');
        $history->addColumn('id', 'guid');
        $history->addColumn('order_id', 'guid');
        $history->addColumn('from_status', 'string', ['length' => 20, 'notnull' => false]);
        $history->addColumn('to_status', 'string', ['length' => 20]);
        $history->addColumn('actor_id', 'guid', ['notnull' => false]);
        $history->addColumn('created_at', 'datetimetz_immutable');
        $history->setPrimaryKey(['id']);
        $history->addForeignKeyConstraint('shop_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_history_order');

        $payment = $schema->createTable('payment');
        $payment->addColumn('id', 'guid');
        $payment->addColumn('order_id', 'guid');
        $payment->addColumn('provider', 'string', ['length' => 30]);
        $payment->addColumn('status', 'string', ['length' => 20]);
        $payment->addColumn('amount_minor', 'bigint');
        $payment->addColumn('currency', 'string', ['length' => 3]);
        $payment->setPrimaryKey(['id']);
        $payment->addUniqueIndex(['order_id'], 'uniq_payment_order');
        $payment->addForeignKeyConstraint('shop_order', ['order_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_payment_order');
    }
}
