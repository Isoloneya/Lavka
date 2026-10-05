<?php

declare(strict_types=1);

namespace App\Shared\Domain;

interface RecordStore
{
    public function find(string $table, string $id): \stdClass;

    public function save(string $table, \stdClass $record, bool $insert): \stdClass;

    public function page(string $table, int $page = 1, int $size = 20): \stdClass;

    public function delete(string $table, string $id): void;

    public function byCode(string $table, string $code): \stdClass;
}
