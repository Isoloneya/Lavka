<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\RegisterUser;
use App\Identity\Infrastructure\Persistence\UserRepository;
use App\Identity\Infrastructure\Security\User;
use App\Shared\Application\Input;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class StaffTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function login(string $role): User
    {
        $register = self::getContainer()->get(RegisterUser::class);
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(RegisterUser::class, $register);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $register->register(new Input((object) ['email' => Uuid::v7()->toRfc4122().'@example.com', 'password' => 'Staff-test-pass-123']));
        $user->role = $role;
        $users->save($user);
        $this->client->loginUser($user, 'storefront');

        return $user;
    }

    public function testStaffAccessAndCsrf(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
        $this->login('ROLE_CUSTOMER');
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/manage/categories', ['name' => 'Forbidden']);
        self::assertResponseStatusCodeSame(403);
        $this->login('ROLE_MANAGER');
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        foreach (['products', 'categories', 'variants', 'warehouses', 'stock', 'lists', 'prices'] as $section) {
            $this->client->request('GET', '/admin/manage/'.$section);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('POST', '/admin/manage/categories', ['name' => 'No CSRF']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/orders?page=0');
        self::assertResponseStatusCodeSame(400);
    }

    public function testProductLifecycleFromEmptyCatalogToFulfilledOrder(): void
    {
        $staff = $this->login('ROLE_MANAGER');
        $slug = 'staff-'.Uuid::v7()->toRfc4122();
        $this->client->request('GET', '/admin/manage/categories');
        $this->client->submitForm('Зберегти', ['name' => 'Staff category', 'slug' => $slug]);
        self::assertResponseStatusCodeSame(303);
        $category = $this->db->fetchOne('SELECT id FROM category WHERE slug = ?', [$slug]);
        $this->client->request('GET', '/admin/manage/products');
        $this->client->submitForm('Зберегти', ['name' => 'Staff product', 'slug' => $slug, 'category_id' => $category, 'status' => 'active', 'attributes' => '{}']);
        self::assertResponseStatusCodeSame(303);
        $product = $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $this->client->request('GET', '/admin/manage/variants');
        $this->client->submitForm('Зберегти', ['product_id' => $product, 'sku' => $slug, 'options' => '{"size":"M"}']);
        self::assertResponseStatusCodeSame(303);
        $variant = $this->db->fetchOne('SELECT id FROM product_variant WHERE sku = ?', [$slug]);
        $this->client->request('GET', '/admin/manage/lists');
        $this->client->submitForm('Зберегти', ['code' => $slug, 'priority' => 90000]);
        self::assertResponseStatusCodeSame(303);
        $list = $this->db->fetchOne('SELECT id FROM price_list WHERE code = ?', [$slug]);
        $this->client->request('GET', '/admin/manage/prices');
        $this->client->submitForm('Зберегти', ['price_list_id' => $list, 'variant_id' => $variant, 'amount_minor' => 150000, 'min_quantity' => 1]);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/admin/manage/warehouses');
        $this->client->submitForm('Зберегти', ['name' => 'Staff warehouse', 'code' => $slug]);
        self::assertResponseStatusCodeSame(303);
        $warehouse = $this->db->fetchOne('SELECT id FROM warehouse WHERE code = ?', [$slug]);
        $this->client->request('GET', '/admin/manage/stock');
        $this->client->submitForm('Зберегти', ['variant_id' => $variant, 'warehouse_id' => $warehouse, 'delta' => 10]);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/shop/'.$slug);
        self::assertSelectorTextContains('.variants', '1 500,00');
        $this->client->submitForm('До кошика');
        $this->client->request('GET', '/checkout');
        $this->client->submitForm('Створити замовлення', ['email' => $staff->email, 'recipient' => 'Тест', 'phone' => '+380501234567', 'city' => 'Київ', 'address' => 'Тестова, 1']);
        self::assertResponseStatusCodeSame(303);
        $number = $this->db->fetchOne('SELECT number FROM shop_order WHERE user_id = ?', [$staff->id]);
        self::assertIsString($number);
        foreach (['Підтвердити тестову оплату', 'В обробку', 'Відправити (демо)', 'Завершити'] as $action) {
            $this->client->request('GET', '/admin/orders/'.$number);
            self::assertResponseIsSuccessful();
            $this->client->submitForm($action);
            self::assertResponseStatusCodeSame(303);
        }
        self::assertSame('completed', $this->db->fetchOne('SELECT status FROM shop_order WHERE number = ?', [$number]));
        $crawler = $this->client->request('GET', '/admin/orders/'.$number);
        $this->client->request('POST', '/admin/orders/'.$number, ['_token' => $crawler->filter('input[name="_token"]')->attr('value'), 'transition' => 'pay']);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('.notice.error');
        self::assertSame(11, (int) $this->db->fetchOne('SELECT COUNT(*) FROM audit_log WHERE actor_id = ?', [$staff->id]));
        $crawler = $this->client->request('GET', '/admin/manage/products?edit='.$product);
        self::assertSelectorNotExists('button[value="archive"]');
        $this->client->request('POST', '/admin/manage/products?edit='.$product, ['_token' => $crawler->filter('input[name="_token"]')->attr('value'), 'operation' => 'archive']);
        self::assertResponseStatusCodeSame(403);
        $this->login('ROLE_ADMIN');
        $this->client->request('GET', '/admin/manage/products?edit='.$product);
        $this->client->submitForm('Архівувати товар');
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/shop/'.$slug);
        self::assertResponseStatusCodeSame(404);
    }

    public function testValidationAndInactiveCategoryEditing(): void
    {
        $this->login('ROLE_MANAGER');
        $slug = 'inactive-'.Uuid::v7()->toRfc4122();
        $this->client->request('GET', '/admin/manage/categories');
        $this->client->submitForm('Зберегти', ['name' => 'Inactive category', 'slug' => $slug, 'is_active' => '0']);
        self::assertResponseStatusCodeSame(303);
        $id = $this->db->fetchOne('SELECT id FROM category WHERE slug = ?', [$slug]);
        $this->client->request('GET', '/admin/manage/categories?edit='.$id);
        self::assertSelectorExists('select[name="is_active"] option[value="0"][selected]');
        $this->client->submitForm('Зберегти', ['slug' => 'NOT VALID']);
        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('slug', 'NOT VALID');
        self::assertSame($slug, $this->db->fetchOne('SELECT slug FROM category WHERE id = ?', [$id]));
    }

    public function testManagementPermissionsAndUserGroups(): void
    {
        $customer = $this->login('ROLE_CUSTOMER');
        $this->client->request('GET', '/admin/promotions');
        self::assertResponseStatusCodeSame(403);
        $this->login('ROLE_MANAGER');
        $this->client->request('GET', '/admin/promotions');
        self::assertResponseIsSuccessful();
        foreach (['users', 'groups', 'audit'] as $section) {
            $this->client->request('GET', '/admin/'.$section);
            self::assertResponseStatusCodeSame(403);
            $this->client->request('POST', '/admin/'.$section);
            self::assertResponseStatusCodeSame('audit' === $section ? 405 : 403);
        }
        $admin = $this->login('ROLE_ADMIN');
        $code = 'vip-'.Uuid::v7()->toRfc4122();
        $this->client->request('GET', '/admin/groups');
        $this->client->submitForm('Створити групу', ['name' => 'VIP test', 'code' => $code]);
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/users', ['_token' => $csrf, 'id' => $customer->id, 'role' => 'ROLE_CUSTOMER', 'customer_group' => $code, 'password' => 'never-log-this']);
        self::assertResponseStatusCodeSame(303);
        self::assertSame($code, $this->db->fetchOne('SELECT customer_group FROM app_user WHERE id = ?', [$customer->id]));
        $this->client->request('POST', '/admin/users', ['_token' => $csrf, 'id' => $admin->id, 'role' => 'ROLE_CUSTOMER', 'customer_group' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('ROLE_ADMIN', $this->db->fetchOne('SELECT role FROM app_user WHERE id = ?', [$admin->id]));
        $this->client->request('GET', '/admin/audit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.audit-json', $code);
        self::assertStringNotContainsString('never-log-this', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', '/admin/groups', ['name' => 'Forged', 'code' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM audit_log WHERE actor_id = ?', [$admin->id]));
    }

    public function testPromotionEditorPreviewAndDisable(): void
    {
        $manager = $this->login('ROLE_MANAGER');
        $command = self::getContainer()->get(\App\Shared\Infrastructure\Console\SeedDemoCommand::class);
        self::assertInstanceOf(\App\Shared\Infrastructure\Console\SeedDemoCommand::class, $command);
        (new \Symfony\Component\Console\Tester\CommandTester($command))->execute([]);
        $coupon = 'STAFF-'.Uuid::v7()->toRfc4122();
        $this->client->request('GET', '/admin/promotions');
        $this->client->submitForm('Зберегти акцію', ['name' => $coupon, 'coupon_code' => $coupon, 'conditions' => '{"all":[]}', 'actions' => '[{"type":"percent_discount","target":"cart","value":10}]']);
        self::assertResponseStatusCodeSame(303);
        $id = $this->db->fetchOne('SELECT id FROM promotion_rule WHERE name = ?', [$coupon]);
        $this->client->followRedirect();
        $this->client->submitForm('Перевірити розрахунок', ['check_sku' => 'DEMO-SHOE-42', 'check_quantity' => 1, 'check_coupon' => $coupon]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section.notice', 'Застосовано: '.$coupon);
        $this->client->request('GET', '/admin/promotions?edit='.$id);
        $this->client->submitForm('Зберегти акцію', ['is_active' => '0', 'valid_from' => '2030-01-01T10:00', 'valid_to' => '2031-01-01T10:00']);
        self::assertResponseStatusCodeSame(303);
        self::assertFalse($this->db->fetchOne('SELECT is_active FROM promotion_rule WHERE id = ?', [$id]));
        $this->client->request('GET', '/admin/promotions?edit='.$id);
        self::assertSelectorExists('select[name="is_active"] option[value="0"][selected]');
        self::assertInputValueSame('valid_from', '2030-01-01T10:00');
        self::assertInputValueSame('valid_to', '2031-01-01T10:00');
        $this->client->submitForm('Зберегти акцію', ['conditions' => 'invalid']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('textarea[name="conditions"]', 'invalid');
        $this->client->request('GET', '/admin/promotions');
        $this->client->submitForm('Зберегти акцію', ['name' => 'Duplicate', 'coupon_code' => $coupon]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.notice.error', 'код купона вже використовується');
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM audit_log WHERE actor_id = ?', [$manager->id]));
    }

    public function testGroupPriceCreatedInAdminAppearsForCustomer(): void
    {
        $customer = $this->login('ROLE_CUSTOMER');
        $this->login('ROLE_ADMIN');
        $command = self::getContainer()->get(\App\Shared\Infrastructure\Console\SeedDemoCommand::class);
        self::assertInstanceOf(\App\Shared\Infrastructure\Console\SeedDemoCommand::class, $command);
        (new \Symfony\Component\Console\Tester\CommandTester($command))->execute([]);
        $code = 'group-'.Uuid::v7()->toRfc4122();
        $this->client->request('GET', '/admin/groups');
        $this->client->submitForm('Створити групу', ['name' => 'Group prices', 'code' => $code]);
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->request('POST', '/admin/users', ['_token' => $crawler->filter('input[name="_token"]')->first()->attr('value'), 'id' => $customer->id, 'role' => 'ROLE_CUSTOMER', 'customer_group' => $code]);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/admin/manage/lists');
        $this->client->submitForm('Зберегти', ['code' => $code, 'priority' => 99999, 'customer_group' => $code]);
        self::assertResponseStatusCodeSame(303);
        $list = $this->db->fetchOne('SELECT id FROM price_list WHERE code = ?', [$code]);
        $variant = $this->db->fetchOne('SELECT id FROM product_variant WHERE sku = ?', ['DEMO-SHOE-42']);
        $this->client->request('GET', '/admin/manage/prices');
        $this->client->submitForm('Зберегти', ['price_list_id' => $list, 'variant_id' => $variant, 'amount_minor' => 50000, 'min_quantity' => 1]);
        self::assertResponseStatusCodeSame(303);
        $this->client->loginUser($customer, 'storefront');
        $this->client->request('GET', '/shop/demo-shoe');
        self::assertSelectorTextContains('.variants', '500,00');
        $this->client->submitForm('До кошика');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.grand-total', '450,00');
    }
}
