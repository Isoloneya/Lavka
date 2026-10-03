<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure;

use App\Pricing\Domain\PricingRepository as Repository;
use App\Shared\Application\ApiProblem;
use App\Shared\Infrastructure\Persistence\Records;

final readonly class PricingRepository implements Repository
{
    public function __construct(private Records $records)
    {
    }

    public function savePrices(string $listId, \stdClass $batch): void
    {
        $this->records->connection->transactional(function () use ($listId, $batch): void {
            foreach ($batch->items as $price) {
                $this->records->connection->executeStatement(
                    'INSERT INTO price (id, price_list_id, variant_id, amount_minor, min_quantity) VALUES (:id, :list, :variant, :amount, :quantity) ON CONFLICT (price_list_id, variant_id, min_quantity) DO UPDATE SET amount_minor = EXCLUDED.amount_minor',
                    ['id' => $price->id, 'list' => $listId, 'variant' => $price->variant_id, 'amount' => $price->amount_minor, 'quantity' => $price->min_quantity],
                );
            }
        });
    }

    public function variant(string $sku): \stdClass
    {
        $row = $this->records->connection->fetchAssociative("SELECT v.id, c.slug AS category FROM product_variant v JOIN product p ON p.id = v.product_id JOIN category c ON c.id = p.category_id WHERE v.sku = :sku AND v.is_active = true AND p.status = 'active' AND c.is_active = true", ['sku' => $sku]);
        if (false === $row) {
            throw new ApiProblem(404, 'VARIANT_NOT_FOUND', 'Активний варіант не знайдено.');
        }

        return (object) $row;
    }

    public function price(string $variantId, string $currency, ?string $group, int $quantity): int
    {
        $amount = $this->records->connection->fetchOne(
            'SELECT p.amount_minor FROM price p JOIN price_list l ON l.id = p.price_list_id WHERE p.variant_id = :variant AND l.currency = :currency AND (l.customer_group IS NULL OR l.customer_group = :group) AND (l.valid_from IS NULL OR l.valid_from <= CURRENT_TIMESTAMP) AND (l.valid_to IS NULL OR l.valid_to > CURRENT_TIMESTAMP) AND p.min_quantity <= :quantity ORDER BY l.priority DESC, (l.customer_group IS NOT NULL) DESC, l.id ASC, p.min_quantity DESC LIMIT 1',
            ['variant' => $variantId, 'currency' => $currency, 'group' => $group, 'quantity' => $quantity],
        );
        if (false === $amount) {
            throw new ApiProblem(409, 'PRICE_NOT_FOUND', 'Ціну для варіанта не знайдено.');
        }

        return (int) $amount;
    }

    public function rules(): \stdClass
    {
        $rows = $this->records->connection->fetchFirstColumn('SELECT to_jsonb(r)::text FROM promotion_rule r ORDER BY priority DESC, id');

        return (object) ['items' => array_map(fn (string $row): \stdClass => $this->records->decode($row), $rows)];
    }

    public function prices(string $listId, int $page, int $size): \stdClass
    {
        $params = ['list' => $listId];
        $total = (int) $this->records->connection->fetchOne('SELECT COUNT(*) FROM price WHERE price_list_id = :list', $params);
        $rows = $this->records->connection->fetchFirstColumn('SELECT to_jsonb(p)::text FROM price p WHERE price_list_id = :list ORDER BY variant_id, min_quantity LIMIT '.$size.' OFFSET '.(($page - 1) * $size), $params);

        return (object) ['items' => array_map(fn (string $row): \stdClass => $this->records->decode($row), $rows), 'total' => $total, 'page' => $page, 'page_size' => $size];
    }
}
