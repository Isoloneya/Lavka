<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use App\Shared\Application\ApiProblem;
use App\Shared\Domain\RecordStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class Records implements RecordStore
{
    private const TABLES = ['category', 'product', 'product_variant', 'price_list', 'price', 'promotion_rule', 'warehouse', 'stock_item', 'audit_log', 'customer_group'];

    public function __construct(public Connection $connection)
    {
    }

    public function find(string $table, string $id): \stdClass
    {
        $this->guardTable($table);
        if (!Uuid::isValid($id)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректний UUID.', 'id');
        }
        $json = $this->connection->fetchOne('SELECT to_jsonb(t)::text FROM '.$table.' t WHERE id = :id', ['id' => $id]);
        if (!is_string($json)) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Запис не знайдено.');
        }

        return $this->decode($json);
    }

    public function save(string $table, \stdClass $record, bool $insert): \stdClass
    {
        $this->guardTable($table);
        $values = get_object_vars($record);
        foreach ($values as $key => $value) {
            if (is_object($value) || is_array($value)) {
                $values[$key] = json_encode($value, JSON_THROW_ON_ERROR);
            } elseif (is_bool($value)) {
                $values[$key] = $value ? 1 : 0;
            }
        }

        if ($insert) {
            $this->connection->insert($table, $values);
        } else {
            $id = $values['id'];
            unset($values['id']);
            $this->connection->update($table, $values, ['id' => $id]);
        }

        return $this->find($table, (string) $record->id);
    }

    public function decode(string $json): \stdClass
    {
        $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        if (!$data instanceof \stdClass) {
            throw new \LogicException('Expected database object.');
        }

        return $data;
    }

    public function page(string $table, int $page = 1, int $size = 20): \stdClass
    {
        $this->guardTable($table);
        if ($page < 1 || $page > 100000 || $size < 1 || $size > 100) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна пагінація.');
        }
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        $rows = $this->connection->fetchFirstColumn('SELECT to_jsonb(t)::text FROM '.$table.' t ORDER BY id DESC LIMIT '.$size.' OFFSET '.(($page - 1) * $size));

        return (object) ['items' => array_map(fn (string $row): \stdClass => $this->decode($row), $rows), 'total' => $total, 'page' => $page, 'page_size' => $size];
    }

    public function delete(string $table, string $id): void
    {
        $this->guardTable($table);
        $this->find($table, $id);
        $this->connection->delete($table, ['id' => $id]);
    }

    public function byCode(string $table, string $code): \stdClass
    {
        if (!in_array($table, ['customer_group', 'warehouse', 'price_list'], true)) {
            throw new \LogicException('Unsupported code lookup.');
        }
        $id = $this->connection->fetchOne('SELECT id FROM '.$table.' WHERE code = :code', ['code' => $code]);
        if (!is_string($id)) {
            throw new ApiProblem(422, 'INVALID_REFERENCE', 'Код довідника не знайдено.');
        }

        return $this->find($table, $id);
    }

    private function guardTable(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \LogicException('Unsupported table.');
        }
    }
}
