<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\RecordStore;

final readonly class RecordBrowser
{
    public function __construct(private RecordStore $store)
    {
    }

    public function page(string $table, int $page, int $size): \stdClass
    {
        return $this->store->page($table, $page, $size);
    }

    public function find(string $table, string $id): \stdClass
    {
        return $this->store->find($table, $id);
    }

    public function delete(string $table, string $id): void
    {
        $this->store->delete($table, $id);
    }
}
