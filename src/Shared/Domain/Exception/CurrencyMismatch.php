<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

final class CurrencyMismatch extends DomainError
{
    public static function between(string $left, string $right): self
    {
        return new self(sprintf('Неможливо виконати операцію над сумами в різних валютах: %s і %s.', $left, $right));
    }

    public function errorCode(): string
    {
        return 'CURRENCY_MISMATCH';
    }
}
