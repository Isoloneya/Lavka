<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Exception;

use App\Shared\Domain\Exception\DomainError;
use Symfony\Component\Uid\Uuid;

final class InsufficientStock extends DomainError
{
    public static function forRequest(Uuid $variantId, Uuid $warehouseId, int $requested): self
    {
        return new self(sprintf(
            'Недостатньо залишку варіанта %s на складі %s: запрошено %d.',
            $variantId,
            $warehouseId,
            $requested,
        ));
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_STOCK';
    }
}
