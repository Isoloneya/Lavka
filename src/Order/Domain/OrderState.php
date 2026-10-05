<?php

declare(strict_types=1);

namespace App\Order\Domain;

final class OrderState
{
    public function __construct(public string $status)
    {
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
