<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Exception\InvalidProduct;
use Symfony\Component\Uid\Uuid;

final class Product
{
    /**
     * @param array<string, mixed> $attributes
     */
    private function __construct(
        private readonly Uuid $id,
        private readonly Uuid $categoryId,
        private string $slug,
        private string $name,
        private ?string $description,
        private array $attributes,
        private ProductStatus $status,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(
        Uuid $id,
        Uuid $categoryId,
        string $slug,
        string $name,
        ?string $description = null,
        array $attributes = [],
    ): self {
        self::guardSlug($slug);
        self::guardName($name);

        return new self($id, $categoryId, $slug, $name, $description, $attributes, ProductStatus::Draft);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function categoryId(): Uuid
    {
        return $this->categoryId;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function status(): ProductStatus
    {
        return $this->status;
    }

    public function rename(string $name): void
    {
        self::guardName($name);
        $this->name = $name;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function changeAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function activate(): void
    {
        if (ProductStatus::Archived === $this->status) {
            throw InvalidProduct::cannotActivateArchivedProduct($this->id);
        }

        $this->status = ProductStatus::Active;
    }

    public function archive(): void
    {
        if (ProductStatus::Archived === $this->status) {
            throw InvalidProduct::alreadyArchived($this->id);
        }

        $this->status = ProductStatus::Archived;
    }

    private static function guardName(string $name): void
    {
        $length = mb_strlen($name);

        if ($length < 1 || $length > 255) {
            throw InvalidProduct::invalidName($name);
        }
    }

    private static function guardSlug(string $slug): void
    {
        if (1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            throw InvalidProduct::invalidSlug($slug);
        }
    }
}
