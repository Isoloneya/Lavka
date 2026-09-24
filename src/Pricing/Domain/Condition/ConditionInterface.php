<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Condition;

use App\Pricing\Domain\PricingContext;
use App\Pricing\Domain\PricingLine;

interface ConditionInterface
{
    public function isSatisfiedBy(PricingContext $context, ?PricingLine $line): bool;
}
