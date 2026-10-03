<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

abstract class DomainError extends \RuntimeException
{
    abstract public function errorCode(): string;
}
