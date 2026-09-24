<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Action;

use App\Pricing\Domain\PricingContext;
use App\Pricing\Domain\PricingLine;
use App\Shared\Domain\Money;

final readonly class PercentageDiscount implements ActionInterface
{
    public function __construct(
        private int $basisPoints,
    ) {
    }

    public function apply(PricingContext $context, ?PricingLine $line): Money
    {
        $base = $line?->subtotal() ?? $context->subtotal();

        return $base->percentage($this->basisPoints);
    }
}
