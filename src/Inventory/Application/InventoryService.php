<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Inventory\Domain\StockAdjuster;
use App\Shared\Application\Input;
use App\Shared\Domain\RecordStore;
use Symfony\Component\Uid\Uuid;

final readonly class InventoryService
{
    public function __construct(private RecordStore $records, private StockAdjuster $adjuster)
    {
    }

    public function warehouse(Input $input): \stdClass
    {
        $input->only('code', 'name');

        return $this->records->save('warehouse', (object) ['id' => Uuid::v7()->toRfc4122(), 'code' => $input->text('code', 64), 'name' => $input->text('name')], true);
    }

    public function adjust(string $variantId, Input $input): \stdClass
    {
        $input->only('warehouse_id', 'delta');
        $warehouseId = $input->uuid('warehouse_id');
        $delta = $input->integer('delta', -1000000000, 1000000000);
        $this->records->find('product_variant', $variantId);
        $this->records->find('warehouse', $warehouseId);

        return $this->adjuster->adjust($variantId, $warehouseId, $delta);
    }
}
