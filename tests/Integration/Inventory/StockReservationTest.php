<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Domain\Exception\InsufficientStock;
use App\Inventory\Domain\StockItem;
use App\Inventory\Domain\StockRepository;
use App\Inventory\Infrastructure\Doctrine\DoctrineStockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class StockReservationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private StockRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineStockRepository($this->entityManager);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema([$this->entityManager->getClassMetadata(StockItem::class)]);
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema([$this->entityManager->getClassMetadata(StockItem::class)]);

        $this->entityManager->close();

        parent::tearDown();
    }

    public function testReservesWithinAvailableQuantity(): void
    {
        $variantId = Uuid::v7();
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
        $variantId = Uuid::v7();
        $warehouseId = Uuid::v7();

        $this->repository->save(StockItem::create(Uuid::v7(), $variantId, $warehouseId, 5));
        $this->entityManager->clear();

        $this->expectException(InsufficientStock::class);

        $this->repository->reserve($variantId, $warehouseId, 6);
    }

    public function testFailedReservationDoesNotChangeReservedQuantity(): void
    {
        $variantId = Uuid::v7();
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
        $variantId = Uuid::v7();
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
}
