<?php

declare(strict_types=1);

namespace App\Storefront\Infrastructure;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class StaffAudit
{
    public function __construct(private Connection $connection)
    {
    }

    public function write(string $actor, string $resource, \stdClass $changes, callable $action): void
    {
        $this->connection->transactional(function () use ($actor, $resource, $changes, $action): void {
            $action();
            $this->connection->insert('audit_log', ['id' => Uuid::v7()->toRfc4122(), 'actor_id' => $actor, 'action' => 'POST', 'resource' => $resource, 'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]);
        });
    }
}
