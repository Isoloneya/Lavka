<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Shared\Application\ApiProblem;
use App\Shared\Infrastructure\Persistence\Records;

final readonly class CatalogQuery
{
    public function __construct(private Records $records)
    {
    }

    public function categories(int $page, int $size): \stdClass
    {
        $total = (int) $this->records->connection->fetchOne('SELECT COUNT(*) FROM category WHERE is_active = true');
        $rows = $this->records->connection->fetchFirstColumn('SELECT to_jsonb(c)::text FROM category c WHERE is_active = true ORDER BY name, id LIMIT '.$size.' OFFSET '.(($page - 1) * $size));

        return (object) ['items' => array_map(fn (string $row): \stdClass => $this->records->decode($row), $rows), 'total' => $total, 'page' => $page, 'page_size' => $size];
    }

    public function products(int $page, int $pageSize, string $search, ?string $category, string $sort, string $currency, ?string $group, ?int $min, ?int $max): \stdClass
    {
        $order = match ($sort) {
            'name' => 'p.name ASC, p.id ASC',
            '-name' => 'p.name DESC, p.id ASC',
            'price' => 'catalog_price.amount ASC NULLS LAST, p.id ASC',
            '-price' => 'catalog_price.amount DESC NULLS LAST, p.id ASC',
            default => 'p.created_at DESC, p.id DESC',
        };
        $where = "p.status = 'active' AND c.is_active = true";
        $params = ['currency' => $currency, 'group' => $group];
        if ('' !== $search) {
            $where .= ' AND (p.name ILIKE :search OR p.description ILIKE :search)';
            $params['search'] = '%'.$search.'%';
        }
        if (null !== $category) {
            $where .= ' AND c.slug = :category';
            $params['category'] = $category;
        }
        if (null !== $min) {
            $where .= ' AND catalog_price.amount >= :min';
            $params['min'] = $min;
        }
        if (null !== $max) {
            $where .= ' AND catalog_price.amount <= :max';
            $params['max'] = $max;
        }

        $from = ' FROM product p JOIN category c ON c.id = p.category_id
            LEFT JOIN LATERAL (
                SELECT MIN(selected.amount_minor) AS amount
                FROM product_variant v
                JOIN LATERAL (
                    SELECT pr.amount_minor FROM price pr JOIN price_list l ON l.id = pr.price_list_id
                    WHERE pr.variant_id = v.id AND pr.min_quantity = 1 AND l.currency = :currency
                      AND (l.customer_group IS NULL OR l.customer_group = :group)
                      AND (l.valid_from IS NULL OR l.valid_from <= CURRENT_TIMESTAMP)
                      AND (l.valid_to IS NULL OR l.valid_to > CURRENT_TIMESTAMP)
                    ORDER BY l.priority DESC, (l.customer_group IS NOT NULL) DESC, l.id ASC LIMIT 1
                ) selected ON true
                WHERE v.product_id = p.id AND v.is_active = true
            ) catalog_price ON true WHERE '.$where;
        $total = (int) $this->records->connection->fetchOne('SELECT COUNT(*)'.$from, $params);
        $rows = $this->records->connection->fetchFirstColumn("SELECT (to_jsonb(p) || jsonb_build_object('base_price_minor', catalog_price.amount, 'currency', CAST(:currency AS text)))::text".$from.' ORDER BY '.$order.' LIMIT '.$pageSize.' OFFSET '.(($page - 1) * $pageSize), $params);

        return (object) ['items' => array_map(fn (string $row): \stdClass => $this->records->decode($row), $rows), 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    public function product(string $slug): \stdClass
    {
        $json = $this->records->connection->fetchOne("SELECT to_jsonb(p)::text FROM product p JOIN category c ON c.id = p.category_id WHERE p.slug = :slug AND p.status = 'active' AND c.is_active = true", ['slug' => $slug]);
        if (!is_string($json)) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Товар не знайдено.');
        }
        $product = $this->records->decode($json);
        $rows = $this->records->connection->fetchFirstColumn('SELECT to_jsonb(v)::text FROM product_variant v WHERE product_id = :id AND is_active = true ORDER BY sku', ['id' => $product->id]);
        $product->variants = array_map(fn (string $row): \stdClass => $this->records->decode($row), $rows);

        return $product;
    }
}
