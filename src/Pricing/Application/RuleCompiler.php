<?php

declare(strict_types=1);

namespace App\Pricing\Application;

use App\Pricing\Domain\Action\PercentageDiscount;
use App\Pricing\Domain\Condition\CategoryIn;
use App\Pricing\Domain\Condition\MinimumCartSubtotal;
use App\Pricing\Domain\Rule;
use App\Pricing\Domain\RuleScope;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use App\Shared\Domain\Money;
use Opis\JsonSchema\Validator;

final class RuleCompiler
{
    private ?\stdClass $schema = null;

    public function compile(\stdClass $definition, string $currency): Rule
    {
        if (null === $this->schema) {
            $json = file_get_contents(dirname(__DIR__, 3).'/docs/promotion-rule.schema.json');
            $schema = false === $json ? null : json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            if (!$schema instanceof \stdClass) {
                throw new \LogicException('Promotion schema is unavailable.');
            }
            $this->schema = $schema;
        }
        if (!(new Validator())->validate($definition, $this->schema)->isValid()) {
            throw new ApiProblem(422, 'INVALID_RULE', 'Правило не відповідає JSON Schema.');
        }

        $input = new Input($definition);
        $conditions = [];
        $conditionInput = new Input($input->object('conditions'));
        $conditionInput->only('all');
        $all = $conditionInput->data->all ?? [];
        if (!is_array($all) || !array_is_list($all) || count($all) > 20) {
            throw new ApiProblem(422, 'INVALID_RULE', 'conditions.all має бути масивом до 20 умов.');
        }

        foreach ($all as $condition) {
            if (!$condition instanceof \stdClass) {
                throw new ApiProblem(422, 'INVALID_RULE', 'Некоректна умова.');
            }
            $item = new Input($condition);
            $item->only('field', 'op', 'value');
            $field = $item->text('field');
            if ('cart.subtotal' === $field && '>=' === $item->text('op')) {
                $conditions[] = new MinimumCartSubtotal(Money::of($item->integer('value', 0, 1000000000000), $currency));
            } elseif ('item.category' === $field && 'in' === $item->text('op')) {
                $categories = $condition->value ?? null;
                if (!is_array($categories) || !array_is_list($categories) || [] === $categories || count($categories) > 100) {
                    throw new ApiProblem(422, 'INVALID_RULE', 'Очікується список категорій.');
                }
                $names = [];
                foreach ($categories as $category) {
                    if (!is_string($category) || strlen($category) > 100) {
                        throw new ApiProblem(422, 'INVALID_RULE', 'Некоректна категорія.');
                    }
                    $names[] = $category;
                }
                $conditions[] = new CategoryIn($names);
            } else {
                throw new ApiProblem(422, 'INVALID_RULE', 'Невідоме поле або оператор.');
            }
        }

        $rawActions = $definition->actions ?? null;
        if (!is_array($rawActions) || !array_is_list($rawActions) || [] === $rawActions || count($rawActions) > 20) {
            throw new ApiProblem(422, 'INVALID_RULE', 'Потрібно від 1 до 20 дій.');
        }
        $actions = [];
        $scope = RuleScope::from($input->choice('scope', 'item', 'cart'));
        foreach ($rawActions as $action) {
            if (!$action instanceof \stdClass) {
                throw new ApiProblem(422, 'INVALID_RULE', 'Некоректна дія.');
            }
            $item = new Input($action);
            $item->only('type', 'target', 'value');
            if ('percent_discount' !== $item->text('type') || $scope->value !== $item->text('target')) {
                throw new ApiProblem(422, 'INVALID_RULE', 'Непідтримувана дія або рівень.');
            }
            $actions[] = new PercentageDiscount($item->integer('value', 0, 100) * 100);
        }

        return new Rule(
            $input->text('id'),
            $input->text('name'),
            $scope,
            $input->integer('priority', -100000, 100000, 0),
            $input->boolean('stop_processing', false),
            $conditions,
            $actions,
            null === ($definition->coupon_code ?? null) ? null : $input->text('coupon_code', 64),
        );
    }
}
