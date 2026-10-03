<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Condition;

use App\Pricing\Domain\PricingContext;
use App\Pricing\Domain\PricingLine;
use App\Shared\Domain\Money;

final readonly class MinimumCartSubtotal implements ConditionInterface
{
    public function __construct(
        private Money $threshold,
    ) {
    }

    public function isSatisfiedBy(PricingContext $context, ?PricingLine $line): bool
    {
        return !$context->subtotal()->isLessThan($this->threshold);
    }
}
