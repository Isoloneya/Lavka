<?php

declare(strict_types=1);

namespace App\Pricing\Domain;

interface PricingRepository
{
    public function savePrices(string $listId, \stdClass $batch): void;

    public function variant(string $sku): \stdClass;

    public function price(string $variantId, string $currency, ?string $group, int $quantity): int;

    public function rules(): \stdClass;

    public function prices(string $listId, int $page, int $size): \stdClass;
}
