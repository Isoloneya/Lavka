<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Domain\Exception\InsufficientStock;
use App\Inventory\Domain\Exception\InvalidStockItem;
use App\Inventory\Domain\StockItem;
use App\Inventory\Domain\StockRepository;
use App\Inventory\Infrastructure\Doctrine\DoctrineStockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class StockReservationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private StockRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        assert($entityManager instanceof EntityManagerInterface);

        $this->entityManager = $entityManager;
        $this->repository = new DoctrineStockRepository($this->entityManager);

        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->entityManager->close();

        parent::tearDown();
    }

    public function testReservesWithinAvailableQuantity(): void
    {
        $variantId = $this->createVariant();
        $warehouseId = Uuid::v7();

        $this->repository->save(StockItem::create(Uuid::v7(), $variantId, $warehouseId, 10));
        $this->entityManager->clear();

        $this->repository->reserve($variantId, $warehouseId, 3);

        $stored = $this->repository->find($variantId, $warehouseId);

        self::assertNotNull($stored);
        self::assertSame(10, $stored->quantity());
        self::assertSame(3, $stored->reserved());
        self::assertSame(7, $stored->available());
    }

    public function testThrowsWhenRequestedQuantityExceedsAvailable(): void
    {
        $variantId = $this->createVariant();
        $warehouseId = Uuid::v7();

        $this->repository->save(StockItem::create(Uuid::v7(), $variantId, $warehouseId, 5));
        $this->entityManager->clear();

        $this->expectException(InsufficientStock::class);

        $this->repository->reserve($variantId, $warehouseId, 6);
    }

    public function testFailedReservationDoesNotChangeReservedQuantity(): void
    {
        $variantId = $this->createVariant();
        $warehouseId = Uuid::v7();

        $this->repository->save(StockItem::create(Uuid::v7(), $variantId, $warehouseId, 5));
        $this->entityManager->clear();

        try {
            $this->repository->reserve($variantId, $warehouseId, 100);
        } catch (InsufficientStock) {
        }

        $stored = $this->repository->find($variantId, $warehouseId);

        self::assertNotNull($stored);
        self::assertSame(0, $stored->reserved());
    }

    public function testSequentialReservationsNeverExceedAvailableQuantity(): void
    {
        $variantId = $this->createVariant();
        $warehouseId = Uuid::v7();

        $this->repository->save(StockItem::create(Uuid::v7(), $variantId, $warehouseId, 5));
        $this->entityManager->clear();

        $succeeded = 0;

        foreach ([3, 3, 3] as $quantity) {
            try {
                $this->repository->reserve($variantId, $warehouseId, $quantity);
                ++$succeeded;
            } catch (InsufficientStock) {
            }
        }

        $stored = $this->repository->find($variantId, $warehouseId);

        self::assertNotNull($stored);
        self::assertSame(1, $succeeded);
        self::assertLessThanOrEqual(5, $stored->reserved());
    }

    public function testRejectsNonPositiveReservationsWithoutChangingStock(): void
    {
        $variantId = $this->createVariant();
        $warehouseId = Uuid::v7();
        $this->repository->save(StockItem::create(Uuid::v7(), $variantId, $warehouseId, 10));
        $this->repository->reserve($variantId, $warehouseId, 3);

        foreach ([0, -1, -4] as $quantity) {
            try {
                $this->repository->reserve($variantId, $warehouseId, $quantity);
                self::fail('Очікувався виняток InvalidStockItem.');
            } catch (InvalidStockItem $exception) {
                self::assertSame('INVALID_STOCK_ITEM', $exception->errorCode());
            }

            $this->entityManager->clear();
            $stored = $this->repository->find($variantId, $warehouseId);
            self::assertNotNull($stored);
            self::assertSame(10, $stored->quantity());
            self::assertSame(3, $stored->reserved());
        }
    }

    private function createVariant(): Uuid
    {
        $connection = $this->entityManager->getConnection();
        $categoryId = Uuid::v7();
        $productId = Uuid::v7();
        $variantId = Uuid::v7();

        $connection->insert('category', [
            'id' => $categoryId->toRfc4122(),
            'parent_id' => null,
            'slug' => 'category-'.$categoryId->toRfc4122(),
            'name' => 'Тестова категорія',
            'attribute_schema' => '{}',
            'is_active' => 1,
        ]);

        $connection->insert('product', [
            'id' => $productId->toRfc4122(),
            'category_id' => $categoryId->toRfc4122(),
            'slug' => 'product-'.$productId->toRfc4122(),
            'name' => 'Тестовий товар',
            'description' => null,
            'attributes' => '{}',
            'status' => 'active',
        ]);

        $connection->insert('product_variant', [
            'id' => $variantId->toRfc4122(),
            'product_id' => $productId->toRfc4122(),
            'sku' => 'SKU-'.$variantId->toRfc4122(),
            'options' => '{}',
            'is_active' => 1,
        ]);

        return $variantId;
    }
}
