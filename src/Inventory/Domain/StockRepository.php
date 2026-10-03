<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

interface StockRepository
{
    public function find(Uuid $variantId, Uuid $warehouseId): ?StockItem;

    public function save(StockItem $stockItem): void;

    public function reserve(Uuid $variantId, Uuid $warehouseId, int $quantity): void;
}
