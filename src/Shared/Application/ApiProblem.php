<?php

declare(strict_types=1);

namespace App\Shared\Application;

final class ApiProblem extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
