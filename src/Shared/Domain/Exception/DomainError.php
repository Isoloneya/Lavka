<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Базовий клас очікуваних помилок домену.
 *
 * Домен нічого не знає про HTTP: відображення коду помилки на HTTP-статус
 * виконується в шарі Infrastructure/Api.
 */
abstract class DomainError extends \RuntimeException
{
    /**
     * Стабільний машинозчитуваний код помилки (наприклад, INSUFFICIENT_STOCK).
     */
    abstract public function errorCode(): string;
}
