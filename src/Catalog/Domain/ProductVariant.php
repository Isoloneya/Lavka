<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Exception\InvalidProduct;
use Symfony\Component\Uid\Uuid;

final class ProductVariant
{
    /**
     * @param array<string, mixed> $options
     */
    private function __construct(
        private readonly Uuid $id,
        private readonly Uuid $productId,
        private readonly string $sku,
        private array $options,
        private bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function create(Uuid $id, Uuid $productId, string $sku, array $options = []): self
    {
        self::guardSku($sku);

        return new self($id, $productId, $sku, $options, true);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function productId(): Uuid
    {
        return $this->productId;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    private static function guardSku(string $sku): void
    {
        $length = mb_strlen($sku);

        if ($length < 1 || $length > 64) {
            throw InvalidProduct::invalidSku($sku);
        }
    }
}
