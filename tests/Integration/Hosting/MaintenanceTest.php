<?php

declare(strict_types=1);

namespace App\Tests\Integration\Hosting;

use App\Cart\Application\CartService;
use App\Order\Application\OrderService;
use App\Shared\Application\Input;
use App\Shared\Infrastructure\Console\SeedDemoCommand;
use App\Shared\Infrastructure\Hosting\Maintenance;
use App\Shared\Infrastructure\Hosting\MaintenanceController;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;

final class MaintenanceTest extends KernelTestCase
{
    private Connection $db;
    private Maintenance $maintenance;
    private DoctrineDbalAdapter $cache;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->db->beginTransaction();
        $seed = self::getContainer()->get(SeedDemoCommand::class);
        self::assertInstanceOf(SeedDemoCommand::class, $seed);
        (new CommandTester($seed))->execute([]);
        $this->cache = new DoctrineDbalAdapter($this->db, 'test-'.bin2hex(random_bytes(8)));
        $orders = self::getContainer()->get(OrderService::class);
        self::assertInstanceOf(OrderService::class, $orders);
        $this->maintenance = new Maintenance($orders, $this->db, new LockFactory(new DoctrineDbalStore($this->db)), $this->cache);
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    public function testExpiryReleasesStockAndDailyRunBypassesThrottle(): void
    {
        $carts = self::getContainer()->get(CartService::class);
        self::assertInstanceOf(CartService::class, $carts);
        $cart = $carts->create(new Input((object) ['currency' => 'UAH']), null);
        $carts->change($cart->token, null, null, 'add', new Input((object) ['sku' => 'DEMO-SHOE-42', 'quantity' => 1]));
        $orders = self::getContainer()->get(OrderService::class);
        self::assertInstanceOf(OrderService::class, $orders);
        $order = $orders->checkout($cart->token, bin2hex(random_bytes(16)), new Input((object) [
            'email' => 'expiry@example.com',
            'shipping_method' => 'pickup',
            'shipping_address' => (object) ['country' => 'UA', 'city' => 'Київ', 'address' => 'Демо 1', 'recipient' => 'Покупець', 'phone' => '+380501234567'],
        ]), null, null);
        self::assertSame(0, $this->maintenance->run());
        $this->db->executeStatement("UPDATE shop_order SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE id = :id", ['id' => $order->id]);
        self::assertSame(0, $this->maintenance->run());
        self::assertSame('pending_payment', $this->db->fetchOne('SELECT status FROM shop_order WHERE id = :id', ['id' => $order->id]));
        $controller = new MaintenanceController($this->maintenance, str_repeat('a', 32));
        $request = Request::create('/internal/maintenance');
        self::assertSame(401, $controller($request)->getStatusCode());
        $request->headers->set('Authorization', 'Bearer '.str_repeat('a', 32));
        self::assertSame(200, $controller($request)->getStatusCode());
        self::assertSame('cancelled', $this->db->fetchOne('SELECT status FROM shop_order WHERE id = :id', ['id' => $order->id]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT SUM(reserved) FROM stock_item'));
        self::assertSame(0, $this->maintenance->run(true));
        self::assertSame(401, (new MaintenanceController($this->maintenance, ''))($request)->getStatusCode());
    }

    public function testDatabaseCacheAndLocksAreSharedAcrossInstances(): void
    {
        $first = new DoctrineDbalAdapter($this->db, 'shared-test');
        $second = new DoctrineDbalAdapter($this->db, 'shared-test');
        $first->save($first->getItem('key')->set('value')->expiresAfter(60));
        self::assertSame('value', $second->getItem('key')->get());
        $firstLock = (new LockFactory(new DoctrineDbalStore($this->db)))->createLock('shared-test', 30);
        $secondLock = (new LockFactory(new DoctrineDbalStore($this->db)))->createLock('shared-test', 30);
        self::assertTrue($firstLock->acquire());
        self::assertFalse($secondLock->acquire());
        $firstLock->release();
        self::assertTrue($secondLock->acquire());
        $secondLock->release();
    }
}
