<?php

declare(strict_types=1);

namespace App\Pricing\Application;

use App\Pricing\Domain\PricingContext;
use App\Pricing\Domain\PricingEngine;
use App\Pricing\Domain\PricingLine;
use App\Pricing\Domain\PricingRepository;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use App\Shared\Domain\Money;
use App\Shared\Domain\RecordStore;
use Symfony\Component\Uid\Uuid;

final readonly class PricingService
{
    public function __construct(private RecordStore $records, private RuleCompiler $compiler, private PricingEngine $engine, private PricingRepository $repository)
    {
    }

    public function priceList(Input $input): \stdClass
    {
        $input->only('code', 'currency', 'customer_group', 'valid_from', 'valid_to', 'priority');
        $currency = $input->currency();
        Money::zero($currency);
        $record = (object) [
            'id' => Uuid::v7()->toRfc4122(),
            'code' => $input->text('code', 64),
            'currency' => $currency,
            'customer_group' => null === ($input->data->customer_group ?? null) ? null : $input->text('customer_group', 64),
            'valid_from' => $input->date('valid_from'),
            'valid_to' => $input->date('valid_to'),
            'priority' => $input->integer('priority', -100000, 100000, 0),
        ];
        $this->validatePeriod($record);

        if (null !== $record->customer_group) {
            $this->records->byCode('customer_group', $record->customer_group);
        }

        return $this->records->save('price_list', $record, true);
    }

    public function prices(string $id, Input $input): \stdClass
    {
        $input->only('prices');
        $this->records->find('price_list', $id);
        $prices = $input->data->prices ?? null;
        if (!is_array($prices) || !array_is_list($prices) || count($prices) > 1000) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Очікується масив до 1000 цін.', 'prices');
        }

        $batch = [];
        foreach ($prices as $raw) {
            if (!$raw instanceof \stdClass) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна ціна.');
            }
            $item = new Input($raw);
            $item->only('variant_id', 'amount_minor', 'min_quantity');
            $variant = $item->uuid('variant_id');
            $this->records->find('product_variant', $variant);
            $batch[] = (object) ['id' => Uuid::v7()->toRfc4122(), 'variant_id' => $variant, 'amount_minor' => $item->integer('amount_minor', 0, 1000000000000), 'min_quantity' => $item->integer('min_quantity', 1, 100, 1)];
        }
        $this->repository->savePrices($id, (object) ['items' => $batch]);

        return (object) ['updated' => count($prices)];
    }

    public function listPrices(string $id, int $page, int $size): \stdClass
    {
        if ($page < 1 || $page > 100000 || $size < 1 || $size > 100) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна пагінація.');
        }
        $this->records->find('price_list', $id);

        return $this->repository->prices($id, $page, $size);
    }

    public function promotion(Input $input, ?string $id = null): \stdClass
    {
        $input->only('name', 'scope', 'priority', 'conditions', 'actions', 'stop_processing', 'coupon_code', 'valid_from', 'valid_to', 'is_active');
        $record = null === $id ? (object) [
            'id' => Uuid::v7()->toRfc4122(), 'priority' => 0, 'conditions' => (object) ['all' => []],
            'stop_processing' => false, 'coupon_code' => null, 'valid_from' => null, 'valid_to' => null, 'is_active' => true,
        ] : $this->records->find('promotion_rule', $id);
        foreach (get_object_vars($input->data) as $key => $value) {
            $record->{$key} = $value;
        }
        $normalized = new Input($record);
        $record->name = $normalized->text('name');
        $record->scope = $normalized->choice('scope', 'item', 'cart');
        $record->priority = $normalized->integer('priority', -100000, 100000);
        $record->stop_processing = $normalized->boolean('stop_processing', false);
        $record->is_active = $normalized->boolean('is_active');
        $record->coupon_code = null === $record->coupon_code ? null : $normalized->text('coupon_code', 64);
        $record->valid_from = $normalized->date('valid_from');
        $record->valid_to = $normalized->date('valid_to');
        $this->validatePeriod($record);
        $this->compiler->compile($record, 'UAH');

        return $this->records->save('promotion_rule', $record, null === $id);
    }

    public function calculate(Input $input, ?string $customerGroup, bool $preview = false): \stdClass
    {
        $preview ? $input->only('currency', 'items', 'coupon_code', 'rule') : $input->only('currency', 'items', 'coupon_code');
        $currency = $input->currency();
        Money::zero($currency);
        $items = $input->data->items ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > 100) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Очікується масив до 100 позицій.', 'items');
        }
        $lines = [];
        $linePrices = new \stdClass();
        $seen = [];
        foreach ($items as $raw) {
            if (!$raw instanceof \stdClass) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна позиція.');
            }
            $item = new Input($raw);
            $item->only('sku', 'quantity');
            $sku = $item->text('sku', 64);
            if (isset($seen[$sku])) {
                throw new ApiProblem(422, 'DUPLICATE_SKU', 'SKU не має повторюватися.');
            }
            $seen[$sku] = true;
            $quantity = $item->integer('quantity', 1, 100);
            $variant = $this->repository->variant($sku);
            $amount = $this->repository->price($variant->id, $currency, $customerGroup, $quantity);
            $linePrices->{$sku} = $amount;
            $lines[] = new PricingLine($sku, $variant->category, Money::of($amount, $currency), $quantity);
        }

        $coupon = null === ($input->data->coupon_code ?? null) ? null : $input->text('coupon_code', 64);
        $rules = [];
        $skipped = [];
        $couponFound = null === $coupon;
        foreach ($this->repository->rules()->items as $record) {
            $now = new \DateTimeImmutable();
            $reason = !$record->is_active ? 'inactive' : ((null !== $record->valid_from && new \DateTimeImmutable($record->valid_from) > $now) || (null !== $record->valid_to && new \DateTimeImmutable($record->valid_to) <= $now) ? 'outside_validity_period' : null);
            if (null !== $reason) {
                $skipped[] = ['rule_id' => $record->id, 'name' => $record->name, 'reason' => $reason];
                continue;
            }
            $couponFound = $couponFound || $record->coupon_code === $coupon;
            $rules[] = $this->compiler->compile($record, $currency);
        }

        if ($preview) {
            $draft = $input->object('rule');
            $draft->id = 'preview';
            $rules[] = $this->compiler->compile($draft, $currency);
            $couponFound = $couponFound || ($draft->coupon_code ?? null) === $coupon;
        }
        if (!$couponFound) {
            throw new ApiProblem(400, 'INVALID_COUPON', 'Купон не існує або не діє.');
        }

        $result = $this->engine->calculate(new PricingContext($currency, $lines, $coupon), $rules);
        $applied = [];
        foreach ($result->appliedRules as $rule) {
            $applied[] = ['rule_id' => $rule->ruleId, 'name' => $rule->name, 'scope' => $rule->scope->value, 'discount' => $rule->discount->amount];
        }
        foreach ($result->skippedRules as $rule) {
            $skipped[] = ['rule_id' => $rule->ruleId, 'name' => $rule->name, 'reason' => $rule->reason];
        }

        return (object) ['currency' => $currency, 'subtotal' => $result->subtotal->amount, 'discount' => $result->discount->amount, 'total' => $result->total->amount, 'applied_rules' => $applied, 'skipped_rules' => $skipped, 'unit_prices' => $linePrices];
    }

    private function validatePeriod(\stdClass $record): void
    {
        if (null !== $record->valid_from && null !== $record->valid_to && new \DateTimeImmutable($record->valid_to) <= new \DateTimeImmutable($record->valid_from)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Кінець періоду має бути пізніше початку.', 'valid_to');
        }
    }
}
