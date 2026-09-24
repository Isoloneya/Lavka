<?php

declare(strict_types=1);

namespace App\Pricing\Domain;

use App\Shared\Domain\Money;

final readonly class AppliedRule
{
    public function __construct(
        public string $ruleId,
        public string $name,
        public RuleScope $scope,
        public Money $discount,
    ) {
    }
}
