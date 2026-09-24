<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Action;

use App\Pricing\Domain\PricingContext;
use App\Pricing\Domain\PricingLine;
use App\Shared\Domain\Money;

interface ActionInterface
{
    public function apply(PricingContext $context, ?PricingLine $line): Money;
}
