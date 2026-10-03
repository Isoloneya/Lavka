<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Doctrine;

use App\Inventory\Domain\StockAdjuster as Adjuster;
use App\Shared\Application\ApiProblem;
use App\Shared\Infrastructure\Persistence\Records;
use Symfony\Component\Uid\Uuid;

final readonly class StockAdjuster implements Adjuster
{
    public function __construct(private Records $records)
    {
    }

    public function adjust(string $variantId, string $warehouseId, int $delta): \stdClass
    {
        return $this->records->connection->transactional(function () use ($variantId, $warehouseId, $delta): \stdClass {
            $this->records->connection->executeStatement(
                'INSERT INTO stock_item (id, variant_id, warehouse_id, quantity, reserved) VALUES (:id, :variant, :warehouse, 0, 0) ON CONFLICT (variant_id, warehouse_id) DO NOTHING',
                ['id' => Uuid::v7()->toRfc4122(), 'variant' => $variantId, 'warehouse' => $warehouseId],
            );
            $json = $this->records->connection->fetchOne(
                'UPDATE stock_item SET quantity = quantity + :delta WHERE variant_id = :variant AND warehouse_id = :warehouse AND quantity::bigint + :delta BETWEEN reserved AND 2147483647 RETURNING row_to_json(stock_item)::text',
                ['variant' => $variantId, 'warehouse' => $warehouseId, 'delta' => $delta],
            );
            if (!is_string($json)) {
                throw new ApiProblem(409, 'INVALID_STOCK_ADJUSTMENT', 'Залишок не може бути меншим за резерв або перевищувати ліміт.');
            }

            return $this->records->decode($json);
        });
    }
}
