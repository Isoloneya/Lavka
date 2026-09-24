<?php

declare(strict_types=1);

namespace App\Pricing\Domain;

final readonly class SkippedRule
{
    public function __construct(
        public string $ruleId,
        public string $name,
        public string $reason,
    ) {
    }
}
