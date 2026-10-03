<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Input;
use App\Shared\Domain\RecordStore;
use Symfony\Component\Uid\Uuid;

final readonly class CustomerGroups
{
    public function __construct(private RecordStore $store)
    {
    }

    public function create(Input $input): \stdClass
    {
        $input->only('code', 'name');

        return $this->store->save('customer_group', (object) ['id' => Uuid::v7()->toRfc4122(), 'code' => $input->text('code', 64), 'name' => $input->text('name')], true);
    }

    public function requireCode(string $code): void
    {
        $this->store->byCode('customer_group', $code);
    }
}
