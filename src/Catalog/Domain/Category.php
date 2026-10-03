<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Exception\InvalidCategory;
use Symfony\Component\Uid\Uuid;

final class Category
{
    /**
     * @param array<string, mixed> $attributeSchema
     */
    private function __construct(
        private readonly Uuid $id,
        private ?Uuid $parentId,
        private string $slug,
        private string $name,
        private array $attributeSchema,
        private bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $attributeSchema
     */
    public static function create(
        Uuid $id,
        ?Uuid $parentId,
        string $slug,
        string $name,
        array $attributeSchema = [],
    ): self {
        self::guardSlug($slug);
        self::guardName($name);

        return new self($id, $parentId, $slug, $name, $attributeSchema, true);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function parentId(): ?Uuid
    {
        return $this->parentId;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributeSchema(): array
    {
        return $this->attributeSchema;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function rename(string $name): void
    {
        self::guardName($name);
        $this->name = $name;
    }

    public function moveTo(?Uuid $parentId): void
    {
        if (null !== $parentId && $parentId->equals($this->id)) {
            throw InvalidCategory::cannotBeOwnParent();
        }

        $this->parentId = $parentId;
    }

    /**
     * @param array<string, mixed> $attributeSchema
     */
    public function changeAttributeSchema(array $attributeSchema): void
    {
        $this->attributeSchema = $attributeSchema;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    private static function guardSlug(string $slug): void
    {
        if (1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            throw InvalidCategory::invalidSlug($slug);
        }
    }

    private static function guardName(string $name): void
    {
        $length = mb_strlen($name);

        if ($length < 1 || $length > 255) {
            throw InvalidCategory::invalidName($name);
        }
    }
}
