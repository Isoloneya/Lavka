<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Inventory\Domain\StockRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class ReserveStock
{
    public function __construct(
        private readonly StockRepository $stockRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<array{variantId: Uuid, warehouseId: Uuid, quantity: int}> $lines
     */
    public function __invoke(array $lines): void
    {
        $this->connection->transactional(function () use ($lines): void {
            foreach ($lines as $line) {
                $this->stockRepository->reserve($line['variantId'], $line['warehouseId'], $line['quantity']);
            }
        });
    }
}
