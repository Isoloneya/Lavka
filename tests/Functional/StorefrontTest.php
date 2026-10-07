<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Application\CatalogService;
use App\Shared\Application\Input;
use App\Shared\Infrastructure\Console\SeedDemoCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class StorefrontTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('REMOTE_ADDR', '198.18.'.random_int(0, 255).'.'.random_int(1, 254));
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $command = self::getContainer()->get(SeedDemoCommand::class);
        self::assertInstanceOf(SeedDemoCommand::class, $command);
        (new CommandTester($command))->execute([]);
    }

    protected function tearDown(): void
    {
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testHomeCatalogAndProductUseRealData(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('video[poster]');
        $this->client->request('GET', '/shop?q=Демонстраційні&category=demo-shoes&sort=price');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.product-grid', 'Демонстраційні кросівки');
        self::assertSelectorTextContains('.product-grid', '1 000,00');
        $this->client->clickLink('Демонстраційні кросівки');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Демонстраційні кросівки');
        self::assertSelectorTextContains('.variants', 'DEMO-SHOE-42');
        self::assertSelectorTextContains('.variants', '1 000,00');
    }

    public function testEmptySearchAndInvalidPagination(): void
    {
        $this->client->request('GET', '/shop?q='.Uuid::v7()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.empty');
        $this->client->request('GET', '/shop?page=0');
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        $this->client->request('GET', '/shop?sort=invalid');
        self::assertResponseStatusCodeSame(400);
    }

    public function testDraftProductsStayHiddenAndContentIsEscaped(): void
    {
        $catalog = self::getContainer()->get(CatalogService::class);
        self::assertInstanceOf(CatalogService::class, $catalog);
        $slug = 'storefront-'.Uuid::v7()->toRfc4122();
        $category = $catalog->category(new Input((object) ['name' => 'Storefront', 'slug' => $slug]));
        $product = $catalog->product(new Input((object) ['name' => '<script>alert(1)</script>', 'slug' => $slug, 'category_id' => $category->id]));
        $this->client->request('GET', '/shop/'.$slug);
        self::assertResponseStatusCodeSame(404);
        $catalog->product(new Input((object) ['status' => 'active']), $product->id);
        $this->client->request('GET', '/shop/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '<script>alert(1)</script>');
        self::assertSelectorNotExists('h1 script');
        self::assertSelectorTextContains('.variants', 'Доступних варіантів поки немає.');
    }

    public function testShoppingJourneyAndCheckoutReplay(): void
    {
        $crawler = $this->client->request('GET', '/shop/demo-shoe');
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->submitForm('До кошика', ['quantity' => 2]);
        self::assertResponseRedirects('/cart', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.grand-total', '1 800,00');
        $this->client->submitForm('Застосувати', ['coupon_code' => 'invalid-coupon']);
        $this->client->followRedirect();
        self::assertSelectorExists('.notice.error');
        self::assertSelectorTextContains('.grand-total', '1 800,00');
        $checkout = $this->client->request('GET', '/checkout');
        self::assertResponseIsSuccessful();
        $payload = ['_token' => $csrf, 'checkout_key' => $checkout->filter('input[name="checkout_key"]')->attr('value'), 'email' => 'storefront@example.com', 'recipient' => 'Тестовий покупець', 'phone' => '+380501234567', 'country' => 'UA', 'city' => 'Київ', 'address' => 'Тестова, 1', 'shipping_method' => 'stub_delivery'];
        $this->client->request('POST', '/checkout', array_replace($payload, ['checkout_key' => 'stale-key']));
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('.notice.error', 'Кошик змінився');
        $this->client->request('POST', '/checkout', $payload);
        self::assertResponseStatusCodeSame(303);
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertIsString($location);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.order-status', 'Очікує тестового підтвердження');
        self::assertSelectorTextContains('.grand-total', '1 800,00');
        $this->client->request('POST', '/checkout', $payload);
        self::assertResponseRedirects($location, 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM shop_order WHERE email = :email', ['email' => 'storefront@example.com']));
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $location);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCartQuantityRemovalAndCsrf(): void
    {
        $this->client->request('POST', '/cart/add', ['sku' => 'DEMO-SHOE-42', 'quantity' => 1]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/shop/demo-shoe');
        $this->client->submitForm('До кошика');
        $this->client->followRedirect();
        $this->client->submitForm('Оновити', ['quantity' => 3]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.grand-total', '2 430,00');
        $this->client->submitForm('Видалити');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.empty', 'У кошику поки порожньо');
        $this->client->request('GET', '/checkout');
        self::assertResponseRedirects('/cart', 303);
    }

    public function testCheckoutKeepsValuesWhenStockIsInsufficient(): void
    {
        $this->client->request('GET', '/shop/demo-shoe');
        $this->client->submitForm('До кошика', ['quantity' => 100]);
        $this->client->request('GET', '/checkout');
        $this->client->submitForm('Створити замовлення', ['email' => 'stock@example.com', 'recipient' => 'Покупець', 'phone' => '+380501234567', 'city' => 'Київ', 'address' => 'Тестова, 1']);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('.notice.error');
        self::assertInputValueSame('email', 'stock@example.com');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM shop_order WHERE email = :email', ['email' => 'stock@example.com']));
    }

    public function testAccountRetainsGuestCartAndProtectsOrders(): void
    {
        $email = Uuid::v7()->toRfc4122().'@example.com';
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/shop/demo-shoe');
        $this->client->submitForm('До кошика');
        $this->client->request('GET', '/register');
        $this->client->submitForm('Зареєструватися', ['email' => $email, 'password' => 'Storefront-pass-123']);
        self::assertResponseRedirects('/login', 303);
        $this->client->followRedirect();
        $this->client->submitForm('Увійти', ['password' => 'Storefront-pass-123']);
        self::assertResponseRedirects('/account', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', $email);
        $this->client->request('GET', '/cart');
        self::assertSelectorTextContains('.cart-row', 'DEMO-SHOE-42');
        $this->client->request('GET', '/checkout');
        $this->client->submitForm('Створити замовлення', ['email' => $email, 'recipient' => 'Покупець', 'phone' => '+380501234567', 'city' => 'Київ', 'address' => 'Тестова, 1']);
        $orderUrl = $this->client->getResponse()->headers->get('Location');
        self::assertIsString($orderUrl);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/account');
        self::assertSelectorCount(1, '.account-orders a');
        $this->client->submitForm('Вийти');
        self::assertResponseRedirects('/', 303);
        $this->client->request('GET', $orderUrl);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/login');
        $this->client->submitForm('Увійти', ['email' => $email, 'password' => 'Storefront-pass-123']);
        $this->client->request('GET', $orderUrl);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/account');
        $this->client->submitForm('Вийти');
        $this->client->request('GET', '/register');
        $other = Uuid::v7()->toRfc4122().'@example.com';
        $this->client->submitForm('Зареєструватися', ['email' => $other, 'password' => 'Storefront-pass-123']);
        $this->client->followRedirect();
        $this->client->submitForm('Увійти', ['password' => 'Storefront-pass-123']);
        $this->client->request('GET', $orderUrl);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAuthFormsRejectCsrfAndInvalidCredentials(): void
    {
        $this->client->request('POST', '/register', ['email' => 'forged@example.com', 'password' => 'Storefront-pass-123']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/login');
        $this->client->submitForm('Увійти', ['email' => 'missing@example.com', 'password' => 'Incorrect-pass-123']);
        $this->client->followRedirect();
        self::assertSelectorExists('.notice.error');
        self::assertInputValueSame('password', '');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/logout');
        self::assertResponseStatusCodeSame(403);
    }
}
