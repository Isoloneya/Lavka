<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Console\SeedFashionCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class FashionSeedTest extends KernelTestCase
{
    public function testSeedCreatesCompleteCatalogAndPreservesChangesOnRerun(): void
    {
        self::bootKernel(['debug' => false]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $command = self::getContainer()->get(SeedFashionCommand::class);
        self::assertInstanceOf(SeedFashionCommand::class, $command);
        $connection->beginTransaction();
        try {
            $tester = new CommandTester($command);
            self::assertSame(0, $tester->execute([]));
            self::assertSame(113, (int) $connection->fetchOne("SELECT COUNT(*) FROM product WHERE slug LIKE 'fashion-%'"));
            self::assertSame(372, (int) $connection->fetchOne("SELECT COUNT(*) FROM product_variant WHERE sku LIKE 'FASHION-%'"));
            self::assertSame(372, (int) $connection->fetchOne("SELECT COUNT(*) FROM stock_item s JOIN product_variant v ON v.id = s.variant_id WHERE v.sku LIKE 'FASHION-%' AND s.quantity > 0"));
            self::assertSame(372, (int) $connection->fetchOne("SELECT COUNT(*) FROM price p JOIN price_list l ON l.id = p.price_list_id WHERE l.code = 'fashion-demo-uah' AND p.amount_minor > 0"));
            $productId = $connection->fetchOne("SELECT id FROM product WHERE slug LIKE 'fashion-%' LIMIT 1");
            $connection->executeStatement('UPDATE product SET name = ? WHERE id = ?', ['Змінена назва', $productId]);
            $connection->executeStatement('UPDATE stock_item SET quantity = quantity - 1 WHERE variant_id IN (SELECT id FROM product_variant WHERE product_id = ?)', [$productId]);
            $before = $connection->fetchAllAssociative('SELECT id, quantity, reserved FROM stock_item ORDER BY id');
            self::assertSame(0, $tester->execute([]));
            self::assertStringContainsString('Додано товарів: 0', $tester->getDisplay());
            self::assertSame('Змінена назва', $connection->fetchOne('SELECT name FROM product WHERE id = ?', [$productId]));
            self::assertSame($before, $connection->fetchAllAssociative('SELECT id, quantity, reserved FROM stock_item ORDER BY id'));
            self::assertSame(113, (int) $connection->fetchOne("SELECT COUNT(*) FROM product WHERE slug LIKE 'fashion-%'"));
            $prices = $connection->fetchAllAssociative('SELECT * FROM price ORDER BY id');
            self::assertSame(0, $tester->execute(['--refresh-media' => true]));
            self::assertNotSame('Змінена назва', $connection->fetchOne('SELECT name FROM product WHERE id = ?', [$productId]));
            self::assertSame($prices, $connection->fetchAllAssociative('SELECT * FROM price ORDER BY id'));
            self::assertSame($before, $connection->fetchAllAssociative('SELECT id, quantity, reserved FROM stock_item ORDER BY id'));
        } finally {
            $connection->rollBack();
        }
    }
}
