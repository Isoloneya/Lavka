<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

final class InvalidMoney extends DomainError
{
    public static function invalidCurrency(string $currency): self
    {
        return new self(sprintf('Некоректний код валюти: "%s". Очікується три великі літери (ISO 4217).', $currency));
    }

    public static function negativeAmount(int $amount): self
    {
        return new self(sprintf('Сума не може бути від\'ємною: %d.', $amount));
    }

    public static function negativeFactor(int $factor): self
    {
        return new self(sprintf('Множник не може бути від\'ємним: %d.', $factor));
    }

    public static function invalidBasisPoints(int $basisPoints): self
    {
        return new self(sprintf('Відсоток має бути в діапазоні від 0 до 10000 базисних пунктів, отримано: %d.', $basisPoints));
    }

    public static function overflow(): self
    {
        return new self('Результат операції перевищує допустимий діапазон суми.');
    }

    public function errorCode(): string
    {
        return 'INVALID_MONEY';
    }
}
