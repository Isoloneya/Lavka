<?php

declare(strict_types=1);

namespace App\Pricing\Domain;

use App\Shared\Domain\Money;

final readonly class PricingContext
{
    /**
     * @param list<PricingLine> $lines
     */
    public function __construct(
        public string $currency,
        public array $lines,
        public ?string $couponCode = null,
    ) {
    }

    public function subtotal(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            $total = $total->add($line->subtotal());
        }

        return $total;
    }
}
