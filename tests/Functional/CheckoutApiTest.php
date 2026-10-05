<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\User;
use App\Order\Application\OrderService;
use App\Shared\Infrastructure\Console\SeedDemoCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class CheckoutApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('REMOTE_ADDR', '198.19.'.random_int(0, 255).'.'.random_int(1, 254));
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $db->beginTransaction();
        $seed = self::getContainer()->get(SeedDemoCommand::class);
        self::assertInstanceOf(SeedDemoCommand::class, $seed);
        self::assertSame(0, (new CommandTester($seed))->execute([]));
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testGuestCheckoutIsIdempotentAndProtectsOrder(): void
    {
        $cart = $this->cart();
        $order = $this->checkout($cart->token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending_payment', $order->status);
        self::assertSame('stub', $order->payment->provider);
        self::assertTrue($order->payment->test_mode);
        self::assertSame(90000, $order->total_minor);
        $again = $this->request('POST', '/api/v1/checkout', $this->address());
        self::assertResponseStatusCodeSame(201);
        self::assertSame($order->id, $again->id);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM shop_order WHERE cart_id = :id', ['id' => $cart->id]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT SUM(quantity) FROM stock_reservation WHERE order_id = :id', ['id' => $order->id]));
        $body = $this->address();
        $body->email = 'other@example.com';
        $this->request('POST', '/api/v1/checkout', $body);
        self::assertResponseStatusCodeSame(409);
        $this->client->setServerParameter('HTTP_X_CART_TOKEN', '');
        $this->request('GET', '/api/v1/orders/'.$order->number);
        self::assertResponseStatusCodeSame(404);
        $this->client->setServerParameter('HTTP_X_CART_TOKEN', $cart->token);
        $this->request('GET', '/api/v1/orders/'.$order->number);
        self::assertResponseIsSuccessful();
        $this->request('POST', '/api/v1/carts/'.$cart->token.'/items', (object) ['sku' => 'DEMO-SHOE-42', 'quantity' => 2]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testStockFailureRollsBackOrderAndReservations(): void
    {
        $this->db->executeStatement('UPDATE stock_item SET quantity = 0, reserved = 0');
        $cart = $this->cart();
        $result = $this->checkout($cart->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('INSUFFICIENT_STOCK', $result->code);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM shop_order WHERE cart_id = :id', ['id' => $cart->id]));
        self::assertSame('active', $this->db->fetchOne('SELECT status FROM cart WHERE id = :id', ['id' => $cart->id]));
    }

    public function testCartUpdatesCouponAndInvalidClientPrices(): void
    {
        $cart = $this->cart();
        $view = $this->request('GET', '/api/v1/carts/'.$cart->token);
        $id = $view->items[0]->id;
        $changed = $this->request('PATCH', '/api/v1/carts/'.$cart->token.'/items/'.$id, (object) ['quantity' => 3]);
        self::assertResponseIsSuccessful();
        self::assertSame(243000, $changed->pricing->total);
        $this->request('PATCH', '/api/v1/carts/'.$cart->token.'/items/'.$id, (object) ['quantity' => 101]);
        self::assertResponseStatusCodeSame(422);
        $this->request('PUT', '/api/v1/carts/'.$cart->token.'/coupon', (object) ['coupon_code' => 'INVALID']);
        self::assertResponseStatusCodeSame(400);
        $this->request('POST', '/api/v1/carts/'.$cart->token.'/items', (object) ['sku' => 'DEMO-SHOE-42', 'quantity' => 1, 'price' => 1]);
        self::assertResponseStatusCodeSame(422);
        $empty = $this->request('DELETE', '/api/v1/carts/'.$cart->token.'/items/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame([], $empty->items);
        $this->checkout($cart->token);
        self::assertResponseStatusCodeSame(400);
    }

    public function testCustomersCannotReadEachOthersCartsOrOrders(): void
    {
        $this->authenticate('ROLE_CUSTOMER');
        $cart = $this->cart();
        $order = $this->checkout($cart->token);
        self::assertResponseStatusCodeSame(201);
        $this->authenticate('ROLE_CUSTOMER');
        $this->request('GET', '/api/v1/carts/'.$cart->token);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/v1/orders/'.$order->number);
        self::assertResponseStatusCodeSame(404);
        $list = $this->request('GET', '/api/v1/orders');
        self::assertSame(0, $list->total);
        $this->request('POST', '/api/v1/admin/orders/'.$order->number.'/transitions/pay');
        self::assertResponseStatusCodeSame(403);
    }

    public function testStubPaymentConsumesStockAndCancellationReturnsItOnce(): void
    {
        $cart = $this->cart();
        $before = (int) $this->db->fetchOne('SELECT SUM(quantity) FROM stock_item');
        $order = $this->checkout($cart->token);
        $this->authenticate('ROLE_MANAGER');
        $paid = $this->request('POST', '/api/v1/admin/orders/'.$order->number.'/transitions/pay');
        self::assertResponseIsSuccessful();
        self::assertSame('paid', $paid->status);
        self::assertSame('simulated_paid', $paid->payment->status);
        self::assertSame($before - 1, (int) $this->db->fetchOne('SELECT SUM(quantity) FROM stock_item'));
        $this->request('POST', '/api/v1/admin/orders/'.$order->number.'/transitions/pay');
        self::assertResponseStatusCodeSame(409);
        $this->request('POST', '/api/v1/admin/orders/'.$order->number.'/transitions/cancel');
        self::assertResponseIsSuccessful();
        self::assertSame($before, (int) $this->db->fetchOne('SELECT SUM(quantity) FROM stock_item'));
        $this->request('POST', '/api/v1/admin/orders/'.$order->number.'/transitions/cancel');
        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, (int) $this->db->fetchOne('SELECT SUM(quantity) FROM stock_item'));
    }

    public function testExpiryReleasesReservationAndCannotBePaid(): void
    {
        $cart = $this->cart();
        $order = $this->checkout($cart->token);
        $this->db->executeStatement("UPDATE shop_order SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE id = :id", ['id' => $order->id]);
        $this->authenticate('ROLE_MANAGER');
        $this->request('POST', '/api/v1/admin/orders/'.$order->number.'/transitions/pay');
        self::assertResponseStatusCodeSame(409);
        $orders = self::getContainer()->get(OrderService::class);
        self::assertInstanceOf(OrderService::class, $orders);
        self::assertSame(1, $orders->expire());
        self::assertSame(0, $orders->expire());
        self::assertSame('released', $this->db->fetchOne('SELECT status FROM stock_reservation WHERE order_id = :id', ['id' => $order->id]));
        $history = $this->request('GET', '/api/v1/orders/'.$order->number.'/history');
        self::assertCount(2, $history->items);
    }

    public function testWorkflowCompletesAndRejectsSkippingSteps(): void
    {
        $cart = $this->cart();
        $order = $this->checkout($cart->token);
        $this->authenticate('ROLE_MANAGER');
        $this->request('POST', '/api/v1/admin/orders/'.$order->number.'/transitions/ship');
        self::assertResponseStatusCodeSame(409);
        foreach (['pay', 'start_processing', 'ship', 'complete'] as $transition) {
            $result = $this->request('POST', '/api/v1/admin/orders/'.$order->number.'/transitions/'.$transition);
            self::assertResponseIsSuccessful();
        }
        self::assertSame('completed', $result->status);
        $this->request('POST', '/api/v1/admin/orders/'.$order->number.'/transitions/cancel');
        self::assertResponseStatusCodeSame(409);
    }

    private function cart(): \stdClass
    {
        return $this->createCartWithItem();
    }

    public function testGraphqlRespectsCartAccessAndRejectsMutations(): void
    {
        $this->authenticate('ROLE_CUSTOMER');
        $cart = $this->cart();
        $this->client->setServerParameter('HTTP_X_CART_TOKEN', $cart->token);
        $result = $this->request('POST', '/api/graphql', (object) ['query' => '{ cart { id pricing { total } } products(page_size: 1) { items { name } total } }']);
        self::assertResponseIsSuccessful();
        self::assertObjectNotHasProperty('errors', $result);
        self::assertSame($cart->id, $result->data->cart->id);
        self::assertSame('90000', $result->data->cart->pricing->total);
        $this->authenticate('ROLE_CUSTOMER');
        $result = $this->request('POST', '/api/graphql', (object) ['query' => '{ cart { id } }']);
        self::assertNull($result->data->cart);
        self::assertStringContainsString('NOT_FOUND', $result->errors[0]->message);
        $result = $this->request('POST', '/api/graphql', (object) ['query' => 'mutation { checkout { id } }']);
        self::assertObjectHasProperty('errors', $result);
    }

    public function testGuestCancellationAndSecondCartItemIsolation(): void
    {
        $first = $this->cart();
        $second = $this->cart();
        $view = $this->request('GET', '/api/v1/carts/'.$second->token);
        $this->request('DELETE', '/api/v1/carts/'.$first->token.'/items/'.$view->items[0]->id);
        self::assertResponseStatusCodeSame(404);
        $order = $this->checkout($first->token);
        $this->request('POST', '/api/v1/orders/'.$order->number.'/cancel');
        self::assertResponseIsSuccessful();
        self::assertSame('released', $this->db->fetchOne('SELECT status FROM stock_reservation WHERE order_id = :id', ['id' => $order->id]));
        $this->request('POST', '/api/v1/orders/'.$order->number.'/cancel');
        self::assertResponseStatusCodeSame(403);
    }

    private function createCartWithItem(): \stdClass
    {
        $cart = $this->request('POST', '/api/v1/carts', (object) ['currency' => 'UAH']);
        self::assertResponseStatusCodeSame(201);
        $this->request('POST', '/api/v1/carts/'.$cart->token.'/items', (object) ['sku' => 'DEMO-SHOE-42', 'quantity' => 1]);
        self::assertResponseIsSuccessful();

        return $cart;
    }

    private function checkout(string $token): \stdClass
    {
        $this->client->setServerParameter('HTTP_X_CART_TOKEN', $token);
        $this->client->setServerParameter('HTTP_IDEMPOTENCY_KEY', Uuid::v7()->toRfc4122());

        return $this->request('POST', '/api/v1/checkout', $this->address());
    }

    private function address(): \stdClass
    {
        return (object) ['email' => 'buyer@example.com', 'shipping_method' => 'pickup', 'shipping_address' => (object) ['country' => 'UA', 'city' => 'Київ', 'address' => 'Хрещатик 1', 'recipient' => 'Покупець', 'phone' => '+380501234567']];
    }

    private function authenticate(string $role): void
    {
        $user = new User(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122().'@example.com');
        $user->role = $role;
        $user->changePassword('unused-test-hash');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($user);
        $em->flush();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $jwt);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$jwt->create($user));
    }

    private function request(string $method, string $url, ?\stdClass $body = null): \stdClass
    {
        $this->client->jsonRequest($method, $url, null === $body ? [] : get_object_vars($body), ['HTTP_ACCEPT' => 'application/json']);
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $data = json_decode($content, false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $data);

        return $data;
    }
}
