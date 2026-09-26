<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

use App\Shared\Domain\Exception\DomainError;

final class InvalidCategory extends DomainError
{
    public static function invalidSlug(string $slug): self
    {
        return new self(sprintf('Некоректний slug категорії: "%s".', $slug));
    }

    public static function invalidName(string $name): self
    {
        return new self('Назва категорії має містити від 1 до 255 символів.');
    }

    public static function cannotBeOwnParent(): self
    {
        return new self('Категорія не може бути власним батьком.');
    }

    public function errorCode(): string
    {
        return 'INVALID_CATEGORY';
    }
}
