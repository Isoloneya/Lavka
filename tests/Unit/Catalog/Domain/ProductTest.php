<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Exception\InvalidProduct;
use App\Catalog\Domain\Product;
use App\Catalog\Domain\ProductStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProductTest extends TestCase
{
    public function testCreatesProductInDraftStatus(): void
    {
        $product = Product::create(
            id: Uuid::v7(),
            categoryId: Uuid::v7(),
            slug: 'shoe-42-black',
            name: 'Кросівки чорні',
        );

        self::assertSame('shoe-42-black', $product->slug());
        self::assertSame('Кросівки чорні', $product->name());
        self::assertNull($product->description());
        self::assertSame([], $product->attributes());
        self::assertSame(ProductStatus::Draft, $product->status());
    }

    #[DataProvider('invalidSlugs')]
    public function testRejectsInvalidSlug(string $slug): void
    {
        $this->expectException(InvalidProduct::class);

        Product::create(Uuid::v7(), Uuid::v7(), $slug, 'Назва');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugs(): iterable
    {
        yield 'uppercase' => ['Shoe-42'];
        yield 'spaces' => ['shoe 42'];
        yield 'empty' => [''];
        yield 'leading dash' => ['-shoe-42'];
        yield 'double dash' => ['shoe--42'];
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(InvalidProduct::class);

        Product::create(Uuid::v7(), Uuid::v7(), 'shoe-42', '');
    }

    public function testRejectsTooLongName(): void
    {
        $this->expectException(InvalidProduct::class);

        Product::create(Uuid::v7(), Uuid::v7(), 'shoe-42', str_repeat('a', 256));
    }

    public function testRename(): void
    {
        $product = Product::create(Uuid::v7(), Uuid::v7(), 'shoe-42', 'Стара назва');

        $product->rename('Нова назва');

        self::assertSame('Нова назва', $product->name());
    }

    public function testActivateTransitionsFromDraftToActive(): void
    {
        $product = Product::create(Uuid::v7(), Uuid::v7(), 'shoe-42', 'Кросівки');

        $product->activate();

        self::assertSame(ProductStatus::Active, $product->status());
    }

    public function testArchiveTransitionsToArchived(): void
    {
        $product = Product::create(Uuid::v7(), Uuid::v7(), 'shoe-42', 'Кросівки');

        $product->archive();

        self::assertSame(ProductStatus::Archived, $product->status());
    }

    public function testCannotActivateArchivedProduct(): void
    {
        $product = Product::create(Uuid::v7(), Uuid::v7(), 'shoe-42', 'Кросівки');
        $product->archive();

        $this->expectException(InvalidProduct::class);

        $product->activate();
    }

    public function testCannotArchiveTwice(): void
    {
        $product = Product::create(Uuid::v7(), Uuid::v7(), 'shoe-42', 'Кросівки');
        $product->archive();

        $this->expectException(InvalidProduct::class);

        $product->archive();
    }

    public function testChangeAttributes(): void
    {
        $product = Product::create(Uuid::v7(), Uuid::v7(), 'shoe-42', 'Кросівки');

        $product->changeAttributes(['color' => 'black', 'size' => 42]);

        self::assertSame(['color' => 'black', 'size' => 42], $product->attributes());
    }
}
