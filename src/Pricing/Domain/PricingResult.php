<?php

declare(strict_types=1);

namespace App\Pricing\Domain;

use App\Shared\Domain\Money;

final readonly class PricingResult
{
    /**
     * @param list<AppliedRule> $appliedRules
     * @param list<SkippedRule> $skippedRules
     */
    public function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $total,
        public array $appliedRules,
        public array $skippedRules,
    ) {
    }
}
