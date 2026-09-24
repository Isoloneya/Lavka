<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Action\PercentageDiscount;
use App\Pricing\Domain\Condition\CategoryIn;
use App\Pricing\Domain\Condition\MinimumCartSubtotal;
use App\Pricing\Domain\PricingContext;
use App\Pricing\Domain\PricingEngine;
use App\Pricing\Domain\PricingLine;
use App\Pricing\Domain\Rule;
use App\Pricing\Domain\RuleScope;
use App\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new PricingEngine();
    }

    public function testAppliesPercentageDiscountOnMatchingCategory(): void
    {
        $line = new PricingLine('SHOE-42-BLK', 'shoes', Money::of(250_000, 'UAH'), 1);
        $context = new PricingContext('UAH', [$line]);

        $rule = new Rule(
            id: 'r1',
            name: '-10% на взуття',
            scope: RuleScope::Item,
            priority: 100,
            stopProcessing: false,
            conditions: [new CategoryIn(['shoes'])],
            actions: [new PercentageDiscount(1_000)],
        );

        $result = $this->engine->calculate($context, [$rule]);

        self::assertSame(250_000, $result->subtotal->amount);
        self::assertSame(25_000, $result->discount->amount);
        self::assertSame(225_000, $result->total->amount);
        self::assertCount(1, $result->appliedRules);
        self::assertSame('r1', $result->appliedRules[0]->ruleId);
        self::assertCount(0, $result->skippedRules);
    }

    public function testSkipsRuleWhenConditionNotMet(): void
    {
        $line = new PricingLine('BAG-01', 'bags', Money::of(100_000, 'UAH'), 1);
        $context = new PricingContext('UAH', [$line]);

        $rule = new Rule(
            id: 'r1',
            name: '-10% на взуття',
            scope: RuleScope::Item,
            priority: 100,
            stopProcessing: false,
            conditions: [new CategoryIn(['shoes'])],
            actions: [new PercentageDiscount(1_000)],
        );

        $result = $this->engine->calculate($context, [$rule]);

        self::assertTrue($result->discount->isZero());
        self::assertCount(0, $result->appliedRules);
        self::assertCount(1, $result->skippedRules);
        self::assertSame('conditions_not_met', $result->skippedRules[0]->reason);
    }

    public function testStopProcessingPreventsLowerPriorityRule(): void
    {
        $line = new PricingLine('SHOE-42-BLK', 'shoes', Money::of(250_000, 'UAH'), 1);
        $context = new PricingContext('UAH', [$line]);

        $highPriority = new Rule(
            id: 'high',
            name: '-10% пріоритетна',
            scope: RuleScope::Item,
            priority: 200,
            stopProcessing: true,
            conditions: [],
            actions: [new PercentageDiscount(1_000)],
        );

        $lowPriority = new Rule(
            id: 'low',
            name: '-5% додаткова',
            scope: RuleScope::Item,
            priority: 100,
            stopProcessing: false,
            conditions: [],
            actions: [new PercentageDiscount(500)],
        );

        $result = $this->engine->calculate($context, [$lowPriority, $highPriority]);

        self::assertSame(25_000, $result->discount->amount);
        self::assertCount(1, $result->appliedRules);
        self::assertSame('high', $result->appliedRules[0]->ruleId);
        self::assertCount(1, $result->skippedRules);
        self::assertSame('low', $result->skippedRules[0]->ruleId);
        self::assertSame('stopped_by_higher_priority_rule', $result->skippedRules[0]->reason);
    }

    public function testCouponRuleAppliesOnlyWithMatchingCode(): void
    {
        $line = new PricingLine('SHOE-42-BLK', 'shoes', Money::of(200_000, 'UAH'), 1);

        $rule = new Rule(
            id: 'coupon',
            name: 'Купон SUMMER',
            scope: RuleScope::Cart,
            priority: 100,
            stopProcessing: false,
            conditions: [],
            actions: [new PercentageDiscount(1_000)],
            couponCode: 'SUMMER',
        );

        $withoutCoupon = new PricingContext('UAH', [$line]);
        $resultWithout = $this->engine->calculate($withoutCoupon, [$rule]);

        self::assertTrue($resultWithout->discount->isZero());
        self::assertSame('coupon_not_provided', $resultWithout->skippedRules[0]->reason);

        $withCoupon = new PricingContext('UAH', [$line], 'SUMMER');
        $resultWith = $this->engine->calculate($withCoupon, [$rule]);

        self::assertSame(20_000, $resultWith->discount->amount);
    }

    public function testCartRuleRequiresMinimumSubtotal(): void
    {
        $line = new PricingLine('SHOE-42-BLK', 'shoes', Money::of(100_000, 'UAH'), 1);
        $context = new PricingContext('UAH', [$line]);

        $rule = new Rule(
            id: 'cart-rule',
            name: '-10% від 2000 грн',
            scope: RuleScope::Cart,
            priority: 100,
            stopProcessing: false,
            conditions: [new MinimumCartSubtotal(Money::of(200_000, 'UAH'))],
            actions: [new PercentageDiscount(1_000)],
        );

        $result = $this->engine->calculate($context, [$rule]);

        self::assertTrue($result->discount->isZero());
        self::assertCount(1, $result->skippedRules);
    }

    public function testDiscountIsCappedAtSubtotalAndNeverGoesNegative(): void
    {
        $line = new PricingLine('SHOE-42-BLK', 'shoes', Money::of(10_000, 'UAH'), 1);
        $context = new PricingContext('UAH', [$line]);

        $itemRule = new Rule(
            id: 'item',
            name: '-100% на взуття',
            scope: RuleScope::Item,
            priority: 100,
            stopProcessing: false,
            conditions: [],
            actions: [new PercentageDiscount(10_000)],
        );

        $cartRule = new Rule(
            id: 'cart',
            name: 'Ще -10% на кошик',
            scope: RuleScope::Cart,
            priority: 100,
            stopProcessing: false,
            conditions: [],
            actions: [new PercentageDiscount(1_000)],
        );

        $result = $this->engine->calculate($context, [$itemRule, $cartRule]);

        self::assertSame(10_000, $result->discount->amount);
        self::assertSame(0, $result->total->amount);
        self::assertCount(2, $result->appliedRules);
    }

    public function testEmptyRuleListReturnsSubtotalAsTotal(): void
    {
        $line = new PricingLine('SHOE-42-BLK', 'shoes', Money::of(50_000, 'UAH'), 2);
        $context = new PricingContext('UAH', [$line]);

        $result = $this->engine->calculate($context, []);

        self::assertSame(100_000, $result->subtotal->amount);
        self::assertTrue($result->discount->isZero());
        self::assertSame(100_000, $result->total->amount);
        self::assertCount(0, $result->appliedRules);
        self::assertCount(0, $result->skippedRules);
    }
}
