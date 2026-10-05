<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

interface StockAdjuster
{
    public function adjust(string $variantId, string $warehouseId, int $delta): \stdClass;
}
