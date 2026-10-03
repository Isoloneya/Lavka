<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use App\Shared\Domain\RecordStore;
use Opis\JsonSchema\Validator;
use Symfony\Component\Uid\Uuid;

final readonly class CatalogService
{
    public function __construct(private RecordStore $records)
    {
    }

    public function category(Input $input, ?string $id = null): \stdClass
    {
        $input->only('name', 'slug', 'parent_id', 'attribute_schema', 'is_active');
        $record = null === $id ? (object) ['id' => Uuid::v7()->toRfc4122(), 'parent_id' => null, 'attribute_schema' => new \stdClass(), 'is_active' => true] : $this->records->find('category', $id);
        if (null === $id || $input->has('name')) {
            $record->name = $input->text('name');
        }
        if (null === $id || $input->has('slug')) {
            $record->slug = $input->slug();
        }
        if ($input->has('parent_id')) {
            $record->parent_id = $input->nullableUuid('parent_id');
            $ancestor = $record->parent_id;
            $seen = [$record->id];
            while (null !== $ancestor) {
                if (in_array($ancestor, $seen, true)) {
                    throw new ApiProblem(409, 'CATEGORY_CYCLE', 'Категорії не можуть утворювати цикл.');
                }
                $seen[] = $ancestor;
                $ancestor = $this->records->find('category', $ancestor)->parent_id;
            }
        }
        if ($input->has('attribute_schema')) {
            $schema = $input->object('attribute_schema');
            $this->validateSchema($schema);
            $record->attribute_schema = $schema;
        }
        if ($input->has('is_active')) {
            $record->is_active = $input->boolean('is_active');
        }

        return $this->records->save('category', $record, null === $id);
    }

    public function product(Input $input, ?string $id = null): \stdClass
    {
        $input->only('category_id', 'name', 'slug', 'description', 'attributes', 'status');
        $record = null === $id ? (object) ['id' => Uuid::v7()->toRfc4122(), 'description' => null, 'attributes' => new \stdClass(), 'status' => 'draft'] : $this->records->find('product', $id);
        if ('archived' === $record->status) {
            throw new ApiProblem(409, 'PRODUCT_ARCHIVED', 'Архівний товар не можна змінити.');
        }
        if (null === $id || $input->has('category_id')) {
            $record->category_id = $input->uuid('category_id');
        }
        if (null === $id || $input->has('name')) {
            $record->name = $input->text('name');
        }
        if (null === $id || $input->has('slug')) {
            $record->slug = $input->slug();
        }
        if ($input->has('description')) {
            $record->description = null === $input->data->description ? null : $input->text('description', 10000);
        }
        if ($input->has('attributes')) {
            $record->attributes = $input->object('attributes');
        }
        if ($input->has('status')) {
            $record->status = $input->choice('status', 'draft', 'active');
        }

        $category = $this->records->find('category', $record->category_id);
        if (!(new Validator())->validate($record->attributes, $category->attribute_schema)->isValid()) {
            throw new ApiProblem(422, 'INVALID_ATTRIBUTES', 'Атрибути не відповідають схемі категорії.', 'attributes');
        }

        return $this->records->save('product', $record, null === $id);
    }

    public function variant(Input $input, ?string $productId = null, ?string $id = null): \stdClass
    {
        $input->only('sku', 'options', 'is_active');
        $record = null === $id ? (object) ['id' => Uuid::v7()->toRfc4122(), 'product_id' => $productId, 'options' => new \stdClass(), 'is_active' => true] : $this->records->find('product_variant', $id);
        $product = $this->records->find('product', $record->product_id);
        if ('archived' === $product->status) {
            throw new ApiProblem(409, 'PRODUCT_ARCHIVED', 'Архівний товар не можна змінити.');
        }
        if (null === $id || $input->has('sku')) {
            $record->sku = $input->text('sku', 64);
        }
        if ($input->has('options')) {
            $record->options = $input->object('options');
        }
        if ($input->has('is_active')) {
            $record->is_active = $input->boolean('is_active');
        }

        return $this->records->save('product_variant', $record, null === $id);
    }

    public function archive(string $id): void
    {
        $record = $this->records->find('product', $id);
        $record->status = 'archived';
        $this->records->save('product', $record, false);
    }

    private function validateSchema(\stdClass $schema): void
    {
        $json = json_encode($schema, JSON_THROW_ON_ERROR);
        if (str_contains($json, '"$ref"') || str_contains($json, '"$dynamicRef"')) {
            throw new ApiProblem(422, 'INVALID_SCHEMA', 'Зовнішні та рекурсивні посилання не підтримуються.', 'attribute_schema');
        }
        try {
            (new Validator())->validate(new \stdClass(), $schema);
        } catch (\Throwable) {
            throw new ApiProblem(422, 'INVALID_SCHEMA', 'Некоректна JSON Schema.', 'attribute_schema');
        }
    }
}
