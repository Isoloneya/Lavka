<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Payment\Domain\PaymentGateway;
use Symfony\Component\Uid\Uuid;

final readonly class StubPaymentGateway implements PaymentGateway
{
    public function create(string $orderId, int $amount, string $currency): \stdClass
    {
        return (object) ['id' => Uuid::v7()->toRfc4122(), 'order_id' => $orderId, 'provider' => 'stub', 'status' => 'pending', 'amount_minor' => $amount, 'currency' => $currency];
    }
}
