<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Doctrine;

use App\Inventory\Domain\Exception\InsufficientStock;
use App\Inventory\Domain\Exception\InvalidStockItem;
use App\Inventory\Domain\StockItem;
use App\Inventory\Domain\StockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineStockRepository implements StockRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function find(Uuid $variantId, Uuid $warehouseId): ?StockItem
    {
        return $this->entityManager->getRepository(StockItem::class)->findOneBy([
            'variantId' => $variantId,
            'warehouseId' => $warehouseId,
        ]);
    }

    public function save(StockItem $stockItem): void
    {
        $this->entityManager->persist($stockItem);
        $this->entityManager->flush();
    }

    public function reserve(Uuid $variantId, Uuid $warehouseId, int $quantity): void
    {
        if ($quantity < 1) {
            throw InvalidStockItem::nonPositiveAdjustment($quantity);
        }

        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE stock_item
             SET reserved = reserved + :quantity
             WHERE variant_id = :variant_id
               AND warehouse_id = :warehouse_id
               AND quantity - reserved >= :quantity',
            [
                'quantity' => $quantity,
                'variant_id' => $variantId->toRfc4122(),
                'warehouse_id' => $warehouseId->toRfc4122(),
            ],
        );

        if (0 === $affected) {
            throw InsufficientStock::forRequest($variantId, $warehouseId, $quantity);
        }
    }
}
