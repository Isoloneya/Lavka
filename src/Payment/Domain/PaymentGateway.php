<?php

declare(strict_types=1);

namespace App\Payment\Domain;

interface PaymentGateway
{
    public function create(string $orderId, int $amount, string $currency): \stdClass;
}
