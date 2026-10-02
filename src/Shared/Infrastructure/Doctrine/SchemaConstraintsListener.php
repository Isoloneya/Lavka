<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class SchemaConstraintsListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();

        $this->addForeignKey(
            $schema,
            'category',
            'parent_id',
            'category',
            'fk_category_parent',
        );

        $this->addForeignKey(
            $schema,
            'product',
            'category_id',
            'category',
            'fk_product_category',
        );

        $this->addForeignKey(
            $schema,
            'product_variant',
            'product_id',
            'product',
            'fk_product_variant_product',
        );

        $this->addForeignKey(
            $schema,
            'stock_item',
            'variant_id',
            'product_variant',
            'fk_stock_item_variant',
        );
    }

    private function addForeignKey(
        Schema $schema,
        string $tableName,
        string $columnName,
        string $referencedTable,
        string $constraintName,
    ): void {
        if (!$schema->hasTable($tableName) || !$schema->hasTable($referencedTable)) {
            return;
        }

        $table = $schema->getTable($tableName);

        if ($table->hasForeignKey($constraintName)) {
            return;
        }

        $table->addForeignKeyConstraint(
            $referencedTable,
            [$columnName],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            $constraintName,
        );
    }
}
