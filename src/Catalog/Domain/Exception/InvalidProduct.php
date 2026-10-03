<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

use App\Shared\Domain\Exception\DomainError;
use Symfony\Component\Uid\Uuid;

final class InvalidProduct extends DomainError
{
    public static function invalidSlug(string $slug): self
    {
        return new self(sprintf('Некоректний slug товару: "%s".', $slug));
    }

    public static function invalidName(string $name): self
    {
        return new self('Назва товару має містити від 1 до 255 символів.');
    }

    public static function invalidSku(string $sku): self
    {
        return new self(sprintf('SKU має містити від 1 до 64 символів, отримано: "%s".', $sku));
    }

    public static function cannotActivateArchivedProduct(Uuid $productId): self
    {
        return new self(sprintf('Неможливо активувати архівний товар %s.', $productId));
    }

    public static function alreadyArchived(Uuid $productId): self
    {
        return new self(sprintf('Товар %s вже архівний.', $productId));
    }

    public function errorCode(): string
    {
        return 'INVALID_PRODUCT';
    }
}
