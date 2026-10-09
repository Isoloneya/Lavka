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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:seed-fashion', description: 'Додати вигадану fashion-колекцію з локальними фото, цінами та залишками')]
final class SeedFashionCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogService $catalog,
        private readonly PricingService $pricing,
        private readonly InventoryService $inventory,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('refresh-media', null, InputOption::VALUE_NONE, 'Оновити зображення й описи та приховати попередні запозичені фото');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->debug) {
            (new SymfonyStyle($input, $output))->error('Для масового імпорту запустіть app:seed-fashion --no-debug.');

            return Command::INVALID;
        }
        $json = file_get_contents($this->projectDir.'/data/fashion-catalog.json');
        if (false === $json) {
            throw new \RuntimeException('Не знайдено fashion-каталог.');
        }
        $manifest = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        foreach ($manifest->products as $item) {
            $path = $item->attributes->image;
            if (!preg_match('/^\d+$/D', $item->source_id) || !preg_match('~^/media/generated-fashion/item-(\d{3})\.webp$~D', $path, $matches) || !$this->validImage($path, $item->sha256)) {
                throw new \RuntimeException('Відсутнє або пошкоджене фото товару '.$item->source_id);
            }
            $hover = '/media/generated-fashion/model-'.$matches[1].'.webp';
            if ($hover !== $item->attributes->image_hover || !$this->validImage($hover, $item->hover_sha256)) {
                throw new \RuntimeException('Відсутнє або пошкоджене фото моделі '.$item->source_id);
            }
        }

        $refreshMedia = (bool) $input->getOption('refresh-media');
        $created = $this->connection->transactional(function () use ($manifest, $refreshMedia): int {
            $this->connection->executeStatement("SELECT pg_advisory_xact_lock(hashtext('lavka-seed-fashion'))");
            $categories = [];
            foreach ($manifest->categories as $category) {
                $slug = 'fashion-'.$category->slug;
                $id = $this->connection->fetchOne('SELECT id FROM category WHERE slug = ?', [$slug]);
                $categories[$category->slug] = false === $id ? $this->catalog->category(new Input((object) ['slug' => $slug, 'name' => $category->name]))->id : $id;
            }
            $listId = $this->connection->fetchOne('SELECT id FROM price_list WHERE code = ?', ['fashion-demo-uah']);
            if (false === $listId) {
                $listId = $this->pricing->priceList(new Input((object) ['code' => 'fashion-demo-uah', 'currency' => 'UAH']))->id;
            }
            $warehouseId = $this->connection->fetchOne('SELECT id FROM warehouse WHERE code = ?', ['fashion-demo']);
            if (false === $warehouseId) {
                $warehouseId = $this->inventory->warehouse(new Input((object) ['code' => 'fashion-demo', 'name' => 'Fashion — демонстраційний склад']))->id;
            }
            $created = 0;
            foreach ($manifest->products as $item) {
                $slug = 'fashion-'.$item->source_id;
                $existing = $this->connection->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
                if (false !== $existing) {
                    if ($refreshMedia && false !== $this->connection->fetchOne('SELECT id FROM product_variant WHERE product_id = ? AND sku LIKE ?', [$existing, 'FASHION-'.$item->source_id.'-%'])) {
                        $this->connection->update('product', ['name' => $item->name, 'description' => $item->description, 'attributes' => json_encode($item->attributes, JSON_THROW_ON_ERROR)], ['id' => $existing]);
                    }
                    continue;
                }
                $product = $this->catalog->product(new Input((object) [
                    'category_id' => $categories[$item->category], 'slug' => $slug, 'name' => $item->name,
                    'description' => $item->description, 'attributes' => $item->attributes, 'status' => 'active',
                ]));
                foreach ($item->sizes as $size) {
                    $variant = $this->catalog->variant(new Input((object) ['sku' => 'FASHION-'.$item->source_id.'-'.$size, 'options' => (object) ['Розмір' => 'ONE' === $size ? 'Універсальний' : $size]]), $product->id);
                    $this->pricing->prices($listId, new Input((object) ['prices' => [(object) ['variant_id' => $variant->id, 'amount_minor' => $item->amount_minor, 'min_quantity' => 1]]]));
                    $this->inventory->adjust($variant->id, new Input((object) ['warehouse_id' => $warehouseId, 'delta' => $item->stock]));
                }
                ++$created;
            }
            if ($refreshMedia) {
                $this->connection->executeStatement("UPDATE product SET status = CASE WHEN status = 'active' THEN 'draft' ELSE status END, attributes = attributes - 'image' - 'image_hover' WHERE slug LIKE 'fashion-%' AND attributes->>'image' LIKE '/media/fashion/%'");
            }

            return $created;
        });
        (new SymfonyStyle($input, $output))->success(sprintf('Додано товарів: %d. %s Ціни й залишки наявних товарів збережено.', $created, $refreshMedia ? 'Назви, описи та фото імпортованих товарів оновлено.' : 'Наявні товари збережено.'));

        return Command::SUCCESS;
    }

    private function validImage(string $path, string $hash): bool
    {
        $absolute = $this->projectDir.'/public'.$path;

        return is_file($absolute) && hash_file('sha256', $absolute) === $hash;
    }
}
