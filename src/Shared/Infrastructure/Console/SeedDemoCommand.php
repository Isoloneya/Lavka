<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Catalog\Application\CatalogService;
use App\Inventory\Application\InventoryService;
use App\Pricing\Application\PricingService;
use App\Shared\Application\Input;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-demo', description: 'Додати демонстраційний каталог без очищення наявних даних')]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogService $catalog,
        private readonly PricingService $pricing,
        private readonly InventoryService $inventory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = $this->connection->transactional(function (): bool {
            $this->connection->executeStatement("SELECT pg_advisory_xact_lock(hashtext('lavka-seed-demo'))");
            if (false !== $this->connection->fetchOne('SELECT id FROM price_list WHERE code = :code', ['code' => 'demo-retail-uah'])) {
                return false;
            }

            $category = $this->catalog->category(new Input((object) ['slug' => 'demo-shoes', 'name' => 'Демонстраційне взуття']));
            $product = $this->catalog->product(new Input((object) ['category_id' => $category->id, 'slug' => 'demo-shoe', 'name' => 'Демонстраційні кросівки', 'status' => 'active']));
            $variant = $this->catalog->variant(new Input((object) ['sku' => 'DEMO-SHOE-42', 'options' => (object) ['size' => 42]]), $product->id);
            $list = $this->pricing->priceList(new Input((object) ['code' => 'demo-retail-uah', 'currency' => 'UAH']));
            $this->pricing->prices($list->id, new Input((object) ['prices' => [
                (object) ['variant_id' => $variant->id, 'amount_minor' => 100000, 'min_quantity' => 1],
                (object) ['variant_id' => $variant->id, 'amount_minor' => 90000, 'min_quantity' => 3],
            ]]));
            $this->pricing->promotion(new Input((object) [
                'name' => 'Демо: 10% на взуття', 'scope' => 'item', 'priority' => 100,
                'conditions' => (object) ['all' => [(object) ['field' => 'item.category', 'op' => 'in', 'value' => ['demo-shoes']]]],
                'actions' => [(object) ['type' => 'percent_discount', 'target' => 'item', 'value' => 10]],
            ]));
            $warehouse = $this->inventory->warehouse(new Input((object) ['code' => 'demo-main', 'name' => 'Демонстраційний склад']));
            $this->inventory->adjust($variant->id, new Input((object) ['warehouse_id' => $warehouse->id, 'delta' => 50]));

            return true;
        });
        $io->success($created ? 'Демо-дані створено. SKU: DEMO-SHOE-42.' : 'Демо-дані вже завантажені.');

        return Command::SUCCESS;
    }
}
