<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\Exception\DomainError;

final class InvalidStockItem extends DomainError
{
    public static function negativeQuantity(int $quantity): self
    {
        return new self(sprintf('Залишок не може бути від\'ємним: %d.', $quantity));
    }

    public static function reservedExceedsQuantity(int $reserved, int $quantity): self
    {
        return new self(sprintf('Резерв (%d) не може перевищувати залишок (%d).', $reserved, $quantity));
    }

    public static function nonPositiveAdjustment(int $amount): self
    {
        return new self(sprintf('Кількість для коригування має бути додатною, отримано: %d.', $amount));
    }

    public function errorCode(): string
    {
        return 'INVALID_STOCK_ITEM';
    }
}
