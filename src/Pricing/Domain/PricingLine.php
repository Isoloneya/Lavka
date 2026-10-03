<?php

declare(strict_types=1);

namespace App\Pricing\Domain;

use App\Pricing\Domain\Exception\InvalidPricingLine;
use App\Shared\Domain\Money;

final readonly class PricingLine
{
    public function __construct(
        public string $sku,
        public string $category,
        public Money $unitPrice,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw InvalidPricingLine::nonPositiveQuantity($quantity);
        }
    }

    public function subtotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }
}
