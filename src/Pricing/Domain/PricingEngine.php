<?php

declare(strict_types=1);

namespace App\Pricing\Domain;

use App\Shared\Domain\Money;

final class PricingEngine
{
    /**
     * @param list<Rule> $rules
     */
    public function calculate(PricingContext $context, array $rules): PricingResult
    {
        $applied = [];
        $skipped = [];
        $totalDiscount = Money::zero($context->currency);

        $itemRules = $this->sortByPriority($this->filterByScope($rules, RuleScope::Item));
        $cartRules = $this->sortByPriority($this->filterByScope($rules, RuleScope::Cart));

        foreach ($context->lines as $line) {
            $lineDiscount = $this->processScope($itemRules, $context, $line, $applied, $skipped);
            $lineDiscount = $lineDiscount->min($line->subtotal());
            $totalDiscount = $totalDiscount->add($lineDiscount);
        }

        $remaining = $context->subtotal()->subtract($totalDiscount);
        $cartDiscount = $this->processScope($cartRules, $context, null, $applied, $skipped);
        $cartDiscount = $cartDiscount->min($remaining);
        $totalDiscount = $totalDiscount->add($cartDiscount);

        $subtotal = $context->subtotal();
        $total = $subtotal->subtract($totalDiscount);

        return new PricingResult($subtotal, $totalDiscount, $total, $applied, $skipped);
    }

    /**
     * @param list<Rule>        $rules
     * @param list<AppliedRule> $applied
     * @param list<SkippedRule> $skipped
     */
    private function processScope(
        array $rules,
        PricingContext $context,
        ?PricingLine $line,
        array &$applied,
        array &$skipped,
    ): Money {
        $scopeDiscount = Money::zero($context->currency);
        $stopped = false;

        foreach ($rules as $rule) {
            if ($stopped) {
                $skipped[] = new SkippedRule($rule->id, $rule->name, 'stopped_by_higher_priority_rule');

                continue;
            }

            if (!$rule->appliesTo($context, $line)) {
                $skipped[] = new SkippedRule($rule->id, $rule->name, $rule->skipReason($context));

                continue;
            }

            $ruleDiscount = Money::zero($context->currency);

            foreach ($rule->actions as $action) {
                $ruleDiscount = $ruleDiscount->add($action->apply($context, $line));
            }

            $applied[] = new AppliedRule($rule->id, $rule->name, $rule->scope, $ruleDiscount);
            $scopeDiscount = $scopeDiscount->add($ruleDiscount);

            if ($rule->stopProcessing) {
                $stopped = true;
            }
        }

        return $scopeDiscount;
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<Rule>
     */
    private function filterByScope(array $rules, RuleScope $scope): array
    {
        return array_values(array_filter($rules, static fn (Rule $rule): bool => $rule->scope === $scope));
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<Rule>
     */
    private function sortByPriority(array $rules): array
    {
        usort($rules, static fn (Rule $a, Rule $b): int => $b->priority <=> $a->priority);

        return $rules;
    }
}
