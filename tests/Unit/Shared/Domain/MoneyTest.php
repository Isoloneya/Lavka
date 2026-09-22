<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Exception\CurrencyMismatch;
use App\Shared\Domain\Exception\InvalidMoney;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testCreatesMoneyInMinorUnits(): void
    {
        $money = Money::of(12_500, 'UAH');

        self::assertSame(12_500, $money->amount);
        self::assertSame('UAH', $money->currency);
    }

    public function testZero(): void
    {
        self::assertTrue(Money::zero('UAH')->isZero());
        self::assertFalse(Money::of(1, 'UAH')->isZero());
    }

    #[DataProvider('invalidCurrencies')]
    public function testRejectsInvalidCurrency(string $currency): void
    {
        $this->expectException(InvalidMoney::class);

        Money::of(100, $currency);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCurrencies(): iterable
    {
        yield 'lowercase' => ['uah'];
        yield 'too short' => ['UA'];
        yield 'too long' => ['UAHH'];
        yield 'empty' => [''];
        yield 'digits' => ['U4H'];
    }

    public function testRejectsNegativeAmount(): void
    {
        $this->expectException(InvalidMoney::class);

        Money::of(-1, 'UAH');
    }

    public function testAdd(): void
    {
        $sum = Money::of(1_000, 'UAH')->add(Money::of(250, 'UAH'));

        self::assertSame(1_250, $sum->amount);
    }

    public function testAddRejectsDifferentCurrencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(1_000, 'UAH')->add(Money::of(1_000, 'USD'));
    }

    public function testAddDetectsOverflow(): void
    {
        $this->expectException(InvalidMoney::class);

        Money::of(PHP_INT_MAX, 'UAH')->add(Money::of(1, 'UAH'));
    }

    public function testSubtract(): void
    {
        $result = Money::of(1_000, 'UAH')->subtract(Money::of(250, 'UAH'));

        self::assertSame(750, $result->amount);
    }

    public function testSubtractRejectsNegativeResult(): void
    {
        $this->expectException(InvalidMoney::class);

        Money::of(100, 'UAH')->subtract(Money::of(101, 'UAH'));
    }

    public function testMultiply(): void
    {
        self::assertSame(3_000, Money::of(1_000, 'UAH')->multiply(3)->amount);
        self::assertSame(0, Money::of(1_000, 'UAH')->multiply(0)->amount);
        self::assertSame(0, Money::zero('UAH')->multiply(5)->amount);
    }

    public function testMultiplyRejectsNegativeFactor(): void
    {
        $this->expectException(InvalidMoney::class);

        Money::of(1_000, 'UAH')->multiply(-1);
    }

    public function testMultiplyDetectsOverflow(): void
    {
        $this->expectException(InvalidMoney::class);

        Money::of(PHP_INT_MAX, 'UAH')->multiply(2);
    }

    #[DataProvider('percentageCases')]
    public function testPercentageRoundsHalfUp(int $amount, int $basisPoints, int $expected): void
    {
        $result = Money::of($amount, 'UAH')->percentage($basisPoints);

        self::assertSame($expected, $result->amount);
        self::assertSame('UAH', $result->currency);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function percentageCases(): iterable
    {
        yield '10% of 250 UAH' => [25_000, 1_000, 2_500];
        yield '12.5% of 200 UAH' => [20_000, 1_250, 2_500];
        yield 'rounds down below half' => [994, 1_000, 99];
        yield 'rounds up at half' => [995, 1_000, 100];
        yield 'rounds up above half' => [999, 1_000, 100];
        yield '0%' => [12_345, 0, 0];
        yield '100%' => [12_345, 10_000, 12_345];
        yield 'zero amount' => [0, 1_000, 0];
    }

    #[DataProvider('outOfRangeBasisPoints')]
    public function testPercentageRejectsOutOfRange(int $basisPoints): void
    {
        $this->expectException(InvalidMoney::class);

        Money::of(1_000, 'UAH')->percentage($basisPoints);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRangeBasisPoints(): iterable
    {
        yield 'negative' => [-1];
        yield 'above 100%' => [10_001];
    }

    public function testPercentageDetectsOverflow(): void
    {
        $this->expectException(InvalidMoney::class);

        Money::of(PHP_INT_MAX, 'UAH')->percentage(1);
    }

    public function testEquality(): void
    {
        self::assertTrue(Money::of(100, 'UAH')->equals(Money::of(100, 'UAH')));
        self::assertFalse(Money::of(100, 'UAH')->equals(Money::of(101, 'UAH')));
        self::assertFalse(Money::of(100, 'UAH')->equals(Money::of(100, 'USD')));
    }

    public function testComparison(): void
    {
        $small = Money::of(100, 'UAH');
        $large = Money::of(200, 'UAH');

        self::assertTrue($large->isGreaterThan($small));
        self::assertFalse($small->isGreaterThan($large));
        self::assertTrue($small->isLessThan($large));
        self::assertFalse($small->isLessThan($small));
    }

    public function testComparisonRejectsDifferentCurrencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(100, 'UAH')->isGreaterThan(Money::of(100, 'USD'));
    }

    public function testMinReturnsSmallerAmount(): void
    {
        $small = Money::of(100, 'UAH');
        $large = Money::of(200, 'UAH');

        self::assertSame(100, $large->min($small)->amount);
        self::assertSame(100, $small->min($large)->amount);
    }

    public function testErrorCodesAreMachineReadable(): void
    {
        try {
            Money::of(-1, 'UAH');
            self::fail('Очікувався виняток InvalidMoney');
        } catch (InvalidMoney $e) {
            self::assertSame('INVALID_MONEY', $e->errorCode());
        }

        try {
            Money::of(1, 'UAH')->add(Money::of(1, 'USD'));
            self::fail('Очікувався виняток CurrencyMismatch');
        } catch (CurrencyMismatch $e) {
            self::assertSame('CURRENCY_MISMATCH', $e->errorCode());
        }
    }
}
