<?php

declare(strict_types=1);

namespace App\Shared\Domain;

interface CommerceRepository
{
    public function transaction(callable $operation): mixed;

    public function createCart(string $tokenHash, ?string $userId, string $currency): \stdClass;

    public function cart(string $tokenHash, bool $lock = false): \stdClass;

    public function saveCart(\stdClass $cart): void;

    public function putItem(string $cartId, string $sku, int $quantity, ?string $itemId = null): void;

    public function removeItem(string $cartId, string $itemId): void;

    public function items(string $cartId): \stdClass;

    public function lockKey(string $key): void;

    public function orderByKey(string $key): ?\stdClass;

    public function createOrder(\stdClass $order, \stdClass $items, int $ttl): \stdClass;

    public function order(string $number, bool $lock = false): \stdClass;

    public function orders(?string $userId, int $page, int $size): \stdClass;

    public function history(string $orderId): \stdClass;

    public function transition(\stdClass $order, string $next, ?string $actorId): void;

    public function expiredOrders(int $limit): \stdClass;
}
