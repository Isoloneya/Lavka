<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Scheduler;

use App\Order\Application\OrderService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExpireReservationsHandler
{
    public function __construct(private OrderService $orders)
    {
    }

    public function __invoke(ExpireReservations $message): void
    {
        $this->orders->expire(1000);
    }
}
