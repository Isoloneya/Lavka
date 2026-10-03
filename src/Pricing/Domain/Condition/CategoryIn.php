<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Condition;

use App\Pricing\Domain\PricingContext;
use App\Pricing\Domain\PricingLine;

final readonly class CategoryIn implements ConditionInterface
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        private array $categories,
    ) {
    }

    public function isSatisfiedBy(PricingContext $context, ?PricingLine $line): bool
    {
        if (null === $line) {
            return false;
        }

        return in_array($line->category, $this->categories, true);
    }
}
