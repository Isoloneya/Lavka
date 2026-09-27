<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Inventory\Domain\Exception\InsufficientStock;
use App\Inventory\Domain\Exception\InvalidStockItem;
use Symfony\Component\Uid\Uuid;

final class StockItem
{
    private function __construct(
        private readonly Uuid $id,
        private readonly Uuid $variantId,
        private readonly Uuid $warehouseId,
        private int $quantity,
        private int $reserved,
    ) {
    }

    public static function create(Uuid $id, Uuid $variantId, Uuid $warehouseId, int $quantity = 0): self
    {
        if ($quantity < 0) {
            throw InvalidStockItem::negativeQuantity($quantity);
        }

        return new self($id, $variantId, $warehouseId, $quantity, 0);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function variantId(): Uuid
    {
        return $this->variantId;
    }

    public function warehouseId(): Uuid
    {
        return $this->warehouseId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function reserved(): int
    {
        return $this->reserved;
    }

    public function available(): int
    {
        return $this->quantity - $this->reserved;
    }

    public function adjustQuantity(int $delta): void
    {
        $newQuantity = $this->quantity + $delta;

        if ($newQuantity < 0) {
            throw InvalidStockItem::negativeQuantity($newQuantity);
        }

        if ($newQuantity < $this->reserved) {
            throw InvalidStockItem::reservedExceedsQuantity($this->reserved, $newQuantity);
        }

        $this->quantity = $newQuantity;
    }

    public function reserve(int $quantity): void
    {
        if ($quantity < 1) {
            throw InvalidStockItem::nonPositiveAdjustment($quantity);
        }

        if ($quantity > $this->available()) {
            throw InsufficientStock::forRequest($this->variantId, $this->warehouseId, $quantity);
        }

        $this->reserved += $quantity;
    }

    public function release(int $quantity): void
    {
        if ($quantity < 1) {
            throw InvalidStockItem::nonPositiveAdjustment($quantity);
        }

        if ($quantity > $this->reserved) {
            throw InvalidStockItem::reservedExceedsQuantity($this->reserved - $quantity, $this->quantity);
        }

        $this->reserved -= $quantity;
    }
}
