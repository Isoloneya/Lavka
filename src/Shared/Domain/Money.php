<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Exception\InvalidMoney;

final readonly class Money
{
    public const MAX_BASIS_POINTS = 10_000;

    private function __construct(
        public int $amount,
        public string $currency,
    ) {
    }

    public static function of(int $amount, string $currency): self
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw InvalidMoney::invalidCurrency($currency);
        }

        if ($amount < 0) {
            throw InvalidMoney::negativeAmount($amount);
        }

        return new self($amount, $currency);
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($this->amount > PHP_INT_MAX - $other->amount) {
            throw InvalidMoney::overflow();
        }

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $result = $this->amount - $other->amount;

        if ($result < 0) {
            throw InvalidMoney::negativeAmount($result);
        }

        return new self($result, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw InvalidMoney::negativeFactor($factor);
        }

        if (0 !== $this->amount && $factor > intdiv(PHP_INT_MAX, $this->amount)) {
            throw InvalidMoney::overflow();
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function percentage(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > self::MAX_BASIS_POINTS) {
            throw InvalidMoney::invalidBasisPoints($basisPoints);
        }

        if (0 !== $basisPoints && $this->amount > intdiv(PHP_INT_MAX - 5_000, $basisPoints)) {
            throw InvalidMoney::overflow();
        }

        $rounded = intdiv($this->amount * $basisPoints + 5_000, self::MAX_BASIS_POINTS);

        return new self($rounded, $this->currency);
    }

    public function isZero(): bool
    {
        return 0 === $this->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function min(self $other): self
    {
        return $other->isLessThan($this) ? $other : $this;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
