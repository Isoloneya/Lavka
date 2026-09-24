<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Exception;

use App\Shared\Domain\Exception\DomainError;

final class InvalidPricingLine extends DomainError
{
    public static function nonPositiveQuantity(int $quantity): self
    {
        return new self(sprintf('Кількість має бути додатною, отримано: %d.', $quantity));
    }

    public function errorCode(): string
    {
        return 'INVALID_PRICING_LINE';
    }
}
