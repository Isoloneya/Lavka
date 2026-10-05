<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\User;
use App\Shared\Infrastructure\Console\SeedDemoCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class CommerceApiTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testRegistrationLoginAndProfile(): void
    {
        $email = Uuid::v7()->toRfc4122().'@example.com';
        $profile = $this->request('POST', '/api/v1/auth/register', (object) ['email' => $email, 'password' => 'Secure-pass-123']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['ROLE_CUSTOMER'], $profile->roles);
        self::assertObjectNotHasProperty('password_hash', $profile);

        $login = $this->request('POST', '/api/v1/auth/login', (object) ['email' => $email, 'password' => 'Secure-pass-123']);
        self::assertResponseIsSuccessful();
        self::assertIsString($login->token);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$login->token);
        $me = $this->request('GET', '/api/v1/me');
        self::assertResponseIsSuccessful();
        self::assertSame($email, $me->email);
        $this->request('POST', '/api/v1/admin/categories', (object) ['slug' => 'forbidden', 'name' => 'Forbidden']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRegistrationRejectsRoleEscalationAndInvalidPassword(): void
    {
        $this->request('POST', '/api/v1/auth/register', (object) ['email' => 'test@example.com', 'password' => 'Secure-pass-123', 'role' => 'ROLE_ADMIN']);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', '/api/v1/auth/register', (object) ['email' => 'test@example.com', 'password' => 'short']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnonymousCannotWriteCatalog(): void
    {
        $problem = $this->request('POST', '/api/v1/admin/categories', (object) ['slug' => 'forbidden', 'name' => 'Forbidden']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('UNAUTHENTICATED', $problem->code);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testCatalogValidationVisibilityAndAdminArchiving(): void
    {
        $this->authenticate('ROLE_MANAGER');
        $category = $this->request('POST', '/api/v1/admin/categories', (object) [
            'slug' => 'shoes-'.Uuid::v7()->toRfc4122(),
            'name' => 'Взуття',
            'attribute_schema' => (object) ['type' => 'object', 'properties' => (object) ['size' => (object) ['type' => 'integer']], 'required' => ['size']],
        ]);
        self::assertResponseStatusCodeSame(201);
        $slug = 'shoe-'.Uuid::v7()->toRfc4122();
        $body = (object) ['category_id' => $category->id, 'slug' => $slug, 'name' => 'Кросівки', 'attributes' => (object) ['size' => 'wrong']];
        $this->request('POST', '/api/v1/admin/products', $body);
        self::assertResponseStatusCodeSame(422);
        $body->attributes = (object) ['size' => 42];
        $product = $this->request('POST', '/api/v1/admin/products', $body);
        self::assertResponseStatusCodeSame(201);
        $this->request('GET', '/api/v1/products/'.$slug);
        self::assertResponseStatusCodeSame(404);
        $this->request('PATCH', '/api/v1/admin/products/'.$product->id, (object) ['status' => 'active']);
        self::assertResponseIsSuccessful();
        $this->request('GET', '/api/v1/products/'.$slug);
        self::assertResponseIsSuccessful();
        $this->request('DELETE', '/api/v1/admin/products/'.$product->id);
        self::assertResponseStatusCodeSame(403);
        $this->authenticate('ROLE_ADMIN');
        $this->request('DELETE', '/api/v1/admin/products/'.$product->id);
        self::assertResponseStatusCodeSame(204);
        $this->request('GET', '/api/v1/products/'.$slug);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTierPricesPromotionPreviewAndAudit(): void
    {
        $this->authenticate('ROLE_ADMIN');
        $variant = $this->createCatalog();
        $list = $this->request('POST', '/api/v1/admin/price-lists', (object) ['code' => 'retail-'.Uuid::v7()->toRfc4122(), 'currency' => 'UAH']);
        self::assertResponseStatusCodeSame(201);
        $this->request('PUT', '/api/v1/admin/price-lists/'.$list->id.'/prices', (object) ['prices' => [
            (object) ['variant_id' => $variant->id, 'amount_minor' => 10000, 'min_quantity' => 1],
            (object) ['variant_id' => $variant->id, 'amount_minor' => 8000, 'min_quantity' => 3],
        ]]);
        self::assertResponseIsSuccessful();
        $rule = (object) [
            'name' => 'Знижка 10%', 'scope' => 'item', 'priority' => 100,
            'conditions' => (object) ['all' => []],
            'actions' => [(object) ['type' => 'percent_discount', 'target' => 'item', 'value' => 10]],
        ];
        $this->request('POST', '/api/v1/admin/promotion-rules', $rule);
        self::assertResponseStatusCodeSame(201);
        $body = (object) ['currency' => 'UAH', 'items' => [(object) ['sku' => $variant->sku, 'quantity' => 3]]];
        $price = $this->request('POST', '/api/v1/pricing/calculate', $body);
        self::assertResponseIsSuccessful();
        self::assertSame(24000, $price->subtotal);
        self::assertSame(2400, $price->discount);
        self::assertSame(21600, $price->total);
        self::assertSame(2400, $price->applied_rules[0]->discount);

        $preview = clone $body;
        $preview->rule = (object) [
            'name' => 'Preview', 'scope' => 'cart', 'priority' => 10,
            'conditions' => (object) ['all' => []],
            'actions' => [(object) ['type' => 'percent_discount', 'target' => 'cart', 'value' => 100]],
        ];
        $result = $this->request('POST', '/api/v1/admin/promotion-rules/preview', $preview);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $result->total);
        self::assertSame(24000, array_sum(array_map(static fn (\stdClass $applied): int => $applied->discount, $result->applied_rules)));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM promotion_rule'));

        $body->coupon_code = 'MISSING';
        $this->request('POST', '/api/v1/pricing/calculate', $body);
        self::assertResponseStatusCodeSame(400);
        $audit = $this->request('GET', '/api/v1/admin/audit-log');
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($audit->items);
    }

    public function testStockCannotBeReducedBelowReservation(): void
    {
        $this->authenticate('ROLE_MANAGER');
        $variant = $this->createCatalog();
        $warehouse = $this->request('POST', '/api/v1/admin/warehouses', (object) ['code' => 'wh-'.Uuid::v7()->toRfc4122(), 'name' => 'Склад']);
        self::assertResponseStatusCodeSame(201);
        $stock = $this->request('PATCH', '/api/v1/admin/stock/'.$variant->id, (object) ['warehouse_id' => $warehouse->id, 'delta' => 10]);
        self::assertResponseIsSuccessful();
        self::assertSame(10, $stock->quantity);
        $this->connection->executeStatement('UPDATE stock_item SET reserved = 8 WHERE id = :id', ['id' => $stock->id]);
        $this->request('PATCH', '/api/v1/admin/stock/'.$variant->id, (object) ['warehouse_id' => $warehouse->id, 'delta' => -3]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(10, (int) $this->connection->fetchOne('SELECT quantity FROM stock_item WHERE id = :id', ['id' => $stock->id]));
    }

    public function testRuleRejectsUnknownExecutableExpressions(): void
    {
        $this->authenticate('ROLE_MANAGER');
        $this->request('POST', '/api/v1/admin/promotion-rules', (object) [
            'name' => 'Invalid', 'scope' => 'item',
            'conditions' => (object) ['all' => [(object) ['field' => 'php', 'op' => 'eval', 'value' => 'exit()']]],
            'actions' => [(object) ['type' => 'percent_discount', 'target' => 'item', 'value' => 10]],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM promotion_rule'));
    }

    public function testCustomerGroupChangesSelectedPriceAndCannotBeSpoofed(): void
    {
        $this->authenticate('ROLE_ADMIN');
        $variant = $this->createCatalog();
        $groupCode = 'wholesale-'.Uuid::v7()->toRfc4122();
        $this->request('POST', '/api/v1/admin/customer-groups', (object) ['code' => $groupCode, 'name' => 'Гурт']);
        self::assertResponseStatusCodeSame(201);
        foreach ([null => 10000, $groupCode => 7000] as $group => $amount) {
            $list = $this->request('POST', '/api/v1/admin/price-lists', (object) ['code' => 'list-'.Uuid::v7()->toRfc4122(), 'currency' => 'UAH', 'customer_group' => '' === $group ? null : $group]);
            self::assertResponseStatusCodeSame(201);
            $this->request('PUT', '/api/v1/admin/price-lists/'.$list->id.'/prices', (object) ['prices' => [(object) ['variant_id' => $variant->id, 'amount_minor' => $amount]]]);
            self::assertResponseIsSuccessful();
        }
        $me = $this->request('GET', '/api/v1/me');
        $this->request('PATCH', '/api/v1/admin/users/'.$me->id, (object) ['customer_group' => $groupCode]);
        self::assertResponseIsSuccessful();
        $body = (object) ['currency' => 'UAH', 'items' => [(object) ['sku' => $variant->sku, 'quantity' => 1]]];
        $price = $this->request('POST', '/api/v1/pricing/calculate', $body);
        self::assertResponseIsSuccessful();
        self::assertSame(7000, $price->total);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $price = $this->request('POST', '/api/v1/pricing/calculate', $body);
        self::assertResponseIsSuccessful();
        self::assertSame(10000, $price->total);
        $body->customer_group = $groupCode;
        $this->request('POST', '/api/v1/pricing/calculate', $body);
        self::assertResponseStatusCodeSame(422);
    }

    public function testExpiredCouponAndUnknownConditionsAreRejected(): void
    {
        $this->authenticate('ROLE_MANAGER');
        $variant = $this->createCatalog();
        $list = $this->request('POST', '/api/v1/admin/price-lists', (object) ['code' => 'list-'.Uuid::v7()->toRfc4122(), 'currency' => 'UAH']);
        $this->request('PUT', '/api/v1/admin/price-lists/'.$list->id.'/prices', (object) ['prices' => [(object) ['variant_id' => $variant->id, 'amount_minor' => 10000]]]);
        $rule = (object) [
            'name' => 'Expired', 'scope' => 'cart', 'coupon_code' => 'EXPIRED',
            'valid_from' => '2020-01-01T00:00:00+00:00', 'valid_to' => '2020-02-01T00:00:00+00:00',
            'conditions' => (object) ['all' => []],
            'actions' => [(object) ['type' => 'percent_discount', 'target' => 'cart', 'value' => 10]],
        ];
        $this->request('POST', '/api/v1/admin/promotion-rules', $rule);
        self::assertResponseStatusCodeSame(201);
        $body = (object) ['currency' => 'UAH', 'items' => [(object) ['sku' => $variant->sku, 'quantity' => 1]], 'coupon_code' => 'EXPIRED'];
        $result = $this->request('POST', '/api/v1/pricing/calculate', $body);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('INVALID_COUPON', $result->code);
        unset($body->coupon_code);
        $result = $this->request('POST', '/api/v1/pricing/calculate', $body);
        self::assertResponseIsSuccessful();
        self::assertSame('outside_validity_period', $result->skipped_rules[0]->reason);
        $rule->conditions = (object) ['any' => []];
        $this->request('POST', '/api/v1/admin/promotion-rules', $rule);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCatalogPriceFilterAndPagination(): void
    {
        $this->authenticate('ROLE_MANAGER');
        $variant = $this->createCatalog();
        $list = $this->request('POST', '/api/v1/admin/price-lists', (object) ['code' => 'list-'.Uuid::v7()->toRfc4122(), 'currency' => 'UAH']);
        $this->request('PUT', '/api/v1/admin/price-lists/'.$list->id.'/prices', (object) ['prices' => [(object) ['variant_id' => $variant->id, 'amount_minor' => 12345]]]);
        $result = $this->request('GET', '/api/v1/products?currency=UAH&price_min=12000&price_max=13000&sort=price&page_size=1');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $result->total);
        self::assertSame(12345, $result->items[0]->base_price_minor);
        $empty = $this->request('GET', '/api/v1/products?price_min=13000');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $empty->total);
        self::assertSame([], $empty->items);
        $this->request('GET', '/api/v1/products?page_size=101');
        self::assertResponseStatusCodeSame(422);
    }

    public function testFailedChangesDoNotCreateAuditEntries(): void
    {
        $this->authenticate('ROLE_MANAGER');
        $body = (object) ['slug' => 'same-'.Uuid::v7()->toRfc4122(), 'name' => 'Категорія'];
        $this->request('POST', '/api/v1/admin/categories', $body);
        self::assertResponseStatusCodeSame(201);
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log');
        $this->request('POST', '/api/v1/admin/categories', $body);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($count, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log'));
        $this->request('GET', '/api/v1/admin/audit-log');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCategoryCycleAndNullFlagsAreRejected(): void
    {
        $this->authenticate('ROLE_MANAGER');
        $first = $this->request('POST', '/api/v1/admin/categories', (object) ['slug' => 'first-'.Uuid::v7()->toRfc4122(), 'name' => 'Перша']);
        $second = $this->request('POST', '/api/v1/admin/categories', (object) ['slug' => 'second-'.Uuid::v7()->toRfc4122(), 'name' => 'Друга', 'parent_id' => $first->id]);
        $this->request('PATCH', '/api/v1/admin/categories/'.$first->id, (object) ['parent_id' => $second->id]);
        self::assertResponseStatusCodeSame(409);
        $this->request('PATCH', '/api/v1/admin/categories/'.$first->id, (object) ['is_active' => null]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOpenApiIncludesImplementedRoutesAndBearerAuthentication(): void
    {
        $document = $this->request('GET', '/api/docs.jsonopenapi');
        self::assertResponseIsSuccessful();
        self::assertObjectHasProperty('/api/v1/pricing/calculate', $document->paths);
        self::assertObjectHasProperty('/api/v1/admin/products', $document->paths);
        self::assertObjectHasProperty('bearerAuth', $document->components->securitySchemes);
        self::assertObjectHasProperty('/api/v1/checkout', $document->paths);
    }

    public function testSwaggerUiIsAvailable(): void
    {
        $this->client->request('GET', '/api/docs', server: ['HTTP_ACCEPT' => 'text/html']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('swagger-ui', (string) $this->client->getResponse()->getContent());
    }

    public function testLoginRateLimitAndInvalidTokenUseProblemDetails(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->request('POST', '/api/v1/auth/login', (object) ['email' => 'missing@example.com', 'password' => 'invalid-password']);
            self::assertResponseStatusCodeSame(401);
        }
        $response = $this->request('POST', '/api/v1/auth/login', (object) ['email' => 'missing@example.com', 'password' => 'invalid-password']);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('RATE_LIMITED', $response->code);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer invalid');
        $this->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testHealthChecksDatabaseAndRedis(): void
    {
        $result = $this->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertTrue($result->database);
        self::assertTrue($result->redis);
    }

    public function testDemoSeedIsRepeatableWithoutPurgingData(): void
    {
        $command = self::getContainer()->get(SeedDemoCommand::class);
        self::assertInstanceOf(SeedDemoCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertSame(0, $tester->execute([]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM price_list WHERE code = :code', ['code' => 'demo-retail-uah']));
        $result = $this->request('POST', '/api/v1/pricing/calculate', (object) ['currency' => 'UAH', 'items' => [(object) ['sku' => 'DEMO-SHOE-42', 'quantity' => 3]]]);
        self::assertResponseIsSuccessful();
        self::assertSame(243000, $result->total);
    }

    public function testUnknownCurrencyAndClientPricesAreRejected(): void
    {
        $this->request('POST', '/api/v1/pricing/calculate', (object) ['currency' => 'ZZZ', 'items' => []]);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', '/api/v1/pricing/calculate', (object) ['currency' => 'UAH', 'items' => [(object) ['sku' => 'SKU', 'quantity' => 1, 'unit_price' => 1]]]);
        self::assertResponseStatusCodeSame(422);
    }

    private function authenticate(string $role): void
    {
        $user = new User(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122().'@example.com');
        $user->role = $role;
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user->changePassword($hasher->hashPassword($user, 'Secure-pass-123'));
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->persist($user);
        $manager->flush();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $jwt);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$jwt->create($user));
    }

    private function createCatalog(): \stdClass
    {
        $category = $this->request('POST', '/api/v1/admin/categories', (object) ['slug' => 'category-'.Uuid::v7()->toRfc4122(), 'name' => 'Категорія']);
        self::assertResponseStatusCodeSame(201);
        $product = $this->request('POST', '/api/v1/admin/products', (object) ['slug' => 'product-'.Uuid::v7()->toRfc4122(), 'name' => 'Товар', 'category_id' => $category->id, 'status' => 'active']);
        self::assertResponseStatusCodeSame(201);
        $variant = $this->request('POST', '/api/v1/admin/products/'.$product->id.'/variants', (object) ['sku' => 'SKU-'.Uuid::v7()->toRfc4122()]);
        self::assertResponseStatusCodeSame(201);

        return $variant;
    }

    private function request(string $method, string $url, ?\stdClass $body = null): \stdClass
    {
        $this->client->jsonRequest($method, $url, null === $body ? [] : get_object_vars($body), ['HTTP_ACCEPT' => str_ends_with($url, '.jsonopenapi') ? 'application/vnd.openapi+json' : 'application/json']);
        $content = $this->client->getResponse()->getContent();
        if (false === $content || '' === $content) {
            return new \stdClass();
        }
        $data = json_decode($content, false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $data);

        return $data;
    }
}
