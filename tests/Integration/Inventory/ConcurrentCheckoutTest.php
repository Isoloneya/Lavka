<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Cart\Application\CartService;
use App\Shared\Application\Input;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

final class ConcurrentCheckoutTest extends KernelTestCase
{
    public function testConcurrentCartsCannotReserveTheSameLastUnit(): void
    {
        $this->runRace(false);
    }

    public function testConcurrentIdenticalCheckoutReturnsOneOrder(): void
    {
        $this->runRace(true);
    }

    private function runRace(bool $sameCart): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $carts = self::getContainer()->get(CartService::class);
        self::assertInstanceOf(CartService::class, $carts);
        $id = Uuid::v7()->toRfc4122();
        $cartIds = [];
        $processes = [];
        try {
            $db->insert('category', ['id' => $id, 'slug' => $id, 'name' => 'Concurrent test', 'attribute_schema' => '{}', 'is_active' => 1]);
            $db->insert('product', ['id' => $id, 'category_id' => $id, 'slug' => $id, 'name' => 'Concurrent test', 'attributes' => '{}', 'status' => 'active', 'created_at' => date(DATE_ATOM)]);
            $db->insert('product_variant', ['id' => $id, 'product_id' => $id, 'sku' => $id, 'options' => '{}', 'is_active' => 1]);
            $db->insert('warehouse', ['id' => $id, 'code' => $id, 'name' => 'Concurrent test']);
            $db->insert('stock_item', ['id' => $id, 'variant_id' => $id, 'warehouse_id' => $id, 'quantity' => 1, 'reserved' => 0]);
            $db->insert('price_list', ['id' => $id, 'code' => $id, 'currency' => 'UAH', 'priority' => 0]);
            $db->insert('price', ['id' => $id, 'price_list_id' => $id, 'variant_id' => $id, 'amount_minor' => 1000, 'min_quantity' => 1]);
            $tokens = [];
            for ($i = 0; $i < ($sameCart ? 1 : 2); ++$i) {
                $cart = $carts->create(new Input((object) ['currency' => 'UAH']), null);
                $cartIds[] = $cart->id;
                $tokens[] = $cart->token;
                $carts->change($cart->token, null, null, 'add', new Input((object) ['sku' => $id, 'quantity' => 1]));
            }
            $key = Uuid::v7()->toRfc4122();
            foreach ([$tokens[0], $tokens[1] ?? $tokens[0]] as $token) {
                $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Support/checkout-worker.php', $token, $sameCart ? $key : Uuid::v7()->toRfc4122()], dirname(__DIR__, 3), timeout: 40);
                $process->start();
                $processes[] = $process;
            }
            $statuses = [];
            $numbers = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), false, 64, JSON_THROW_ON_ERROR);
                $statuses[] = $result->status;
                if (isset($result->number)) {
                    $numbers[] = $result->number;
                }
            }
            sort($statuses);
            self::assertSame($sameCart ? [201, 201] : [201, 409], $statuses);
            self::assertCount(1, array_unique($numbers));
            self::assertSame(1, (int) $db->fetchOne('SELECT reserved FROM stock_item WHERE id = :id', ['id' => $id]));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM order_item WHERE variant_id = :id', ['id' => $id]));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($cartIds as $cartId) {
                $orderId = $db->fetchOne('SELECT id FROM shop_order WHERE cart_id = :id', ['id' => $cartId]);
                if (is_string($orderId)) {
                    foreach (['payment', 'stock_reservation', 'order_status_history', 'order_item'] as $table) {
                        $db->delete($table, ['order_id' => $orderId]);
                    }
                    $db->delete('shop_order', ['id' => $orderId]);
                }
                $db->delete('cart', ['id' => $cartId]);
            }
            foreach (['price', 'price_list', 'stock_item', 'warehouse', 'product_variant', 'product', 'category'] as $table) {
                $db->delete($table, ['id' => $id]);
            }
            self::ensureKernelShutdown();
        }
    }
}
