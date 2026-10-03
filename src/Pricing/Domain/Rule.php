<?php

declare(strict_types=1);

namespace App\Pricing\Domain;

use App\Pricing\Domain\Action\ActionInterface;
use App\Pricing\Domain\Condition\ConditionInterface;

final readonly class Rule
{
    /**
     * @param list<ConditionInterface> $conditions
     * @param list<ActionInterface>    $actions
     */
    public function __construct(
        public string $id,
        public string $name,
        public RuleScope $scope,
        public int $priority,
        public bool $stopProcessing,
        public array $conditions,
        public array $actions,
        public ?string $couponCode = null,
    ) {
    }

    public function appliesTo(PricingContext $context, ?PricingLine $line): bool
    {
        if (null !== $this->couponCode && $this->couponCode !== $context->couponCode) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!$condition->isSatisfiedBy($context, $line)) {
                return false;
            }
        }

        return true;
    }

    public function skipReason(PricingContext $context): string
    {
        if (null !== $this->couponCode && $this->couponCode !== $context->couponCode) {
            return 'coupon_not_provided';
        }

        return 'conditions_not_met';
    }
}
