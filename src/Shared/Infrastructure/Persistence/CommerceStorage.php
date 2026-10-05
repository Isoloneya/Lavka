<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use App\Payment\Domain\PaymentGateway;
use App\Shared\Application\ApiProblem;
use App\Shared\Domain\CommerceRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CommerceStorage implements CommerceRepository
{
    public function __construct(private Connection $db, private PaymentGateway $payments)
    {
    }

    public function transaction(callable $operation): mixed
    {
        return $this->db->transactional(static fn (): mixed => $operation());
    }

    public function createCart(string $tokenHash, ?string $userId, string $currency): \stdClass
    {
        $this->db->insert('cart', ['id' => Uuid::v7()->toRfc4122(), 'token_hash' => $tokenHash, 'user_id' => $userId, 'currency' => $currency, 'coupon_code' => null, 'status' => 'active', 'updated_at' => $this->now()]);

        return $this->cart($tokenHash);
    }

    public function cart(string $tokenHash, bool $lock = false): \stdClass
    {
        return $this->one('SELECT to_jsonb(c)::text FROM cart c WHERE token_hash = :hash'.($lock ? ' FOR UPDATE' : ''), (object) ['hash' => $tokenHash]);
    }

    public function saveCart(\stdClass $cart): void
    {
        $this->db->update('cart', ['coupon_code' => $cart->coupon_code, 'status' => $cart->status, 'updated_at' => $this->now()], ['id' => $cart->id]);
    }

    public function putItem(string $cartId, string $sku, int $quantity, ?string $itemId = null): void
    {
        if (null !== $itemId) {
            $count = $this->db->executeStatement('UPDATE cart_item SET quantity = :quantity WHERE id::text = :id AND cart_id = :cart', ['quantity' => $quantity, 'id' => $itemId, 'cart' => $cartId]);
            if (0 === $count) {
                throw new ApiProblem(404, 'NOT_FOUND', 'Позицію не знайдено.');
            }

            return;
        }
        $variant = $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id JOIN category c ON c.id = p.category_id WHERE v.sku = :sku AND v.is_active AND p.status = 'active' AND c.is_active", ['sku' => $sku]);
        if (!is_string($variant)) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Активний SKU не знайдено.');
        }
        $this->db->executeStatement('INSERT INTO cart_item (id, cart_id, variant_id, quantity) VALUES (:id, :cart, :variant, :quantity) ON CONFLICT (cart_id, variant_id) DO UPDATE SET quantity = EXCLUDED.quantity', ['id' => Uuid::v7()->toRfc4122(), 'cart' => $cartId, 'variant' => $variant, 'quantity' => $quantity]);
    }

    public function removeItem(string $cartId, string $itemId): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM cart_item WHERE id::text = :id AND cart_id = :cart', ['id' => $itemId, 'cart' => $cartId])) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Позицію не знайдено.');
        }
    }

    public function items(string $cartId): \stdClass
    {
        return $this->many('SELECT jsonb_build_object(\'id\', i.id, \'variant_id\', i.variant_id, \'sku\', v.sku, \'name\', p.name, \'quantity\', i.quantity)::text FROM cart_item i JOIN product_variant v ON v.id = i.variant_id JOIN product p ON p.id = v.product_id WHERE i.cart_id = :cart ORDER BY i.variant_id', (object) ['cart' => $cartId]);
    }

    public function lockKey(string $key): void
    {
        $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key]);
    }

    public function orderByKey(string $key): ?\stdClass
    {
        $number = $this->db->fetchOne('SELECT number FROM shop_order WHERE idempotency_key = :key', ['key' => $key]);

        return is_string($number) ? $this->order($number) : null;
    }

    public function createOrder(\stdClass $order, \stdClass $items, int $ttl): \stdClass
    {
        $order->id = Uuid::v7()->toRfc4122();
        $order->number = 'LV-'.strtoupper(str_replace('-', '', $order->id));
        $order->status = 'pending_payment';
        $order->placed_at = $this->now();
        $order->expires_at = (new \DateTimeImmutable())->modify('+'.$ttl.' seconds')->format(\DateTimeInterface::ATOM);
        $values = get_object_vars($order);
        foreach (['pricing_snapshot', 'shipping_address'] as $field) {
            $values[$field] = json_encode($values[$field], JSON_THROW_ON_ERROR);
        }
        $this->db->insert('shop_order', $values);
        foreach ($items->items as $item) {
            $this->db->insert('order_item', ['id' => Uuid::v7()->toRfc4122(), 'order_id' => $order->id, 'variant_id' => $item->variant_id, 'sku' => $item->sku, 'name' => $item->name, 'quantity' => $item->quantity, 'unit_price_minor' => $item->unit_price_minor]);
            $remaining = $item->quantity;
            $stocks = $this->db->fetchAllAssociative('SELECT id, quantity - reserved AS available FROM stock_item WHERE variant_id = :variant ORDER BY warehouse_id FOR UPDATE', ['variant' => $item->variant_id]);
            foreach ($stocks as $stock) {
                $take = min($remaining, (int) $stock['available']);
                if ($take <= 0) {
                    continue;
                }
                $changed = $this->db->executeStatement('UPDATE stock_item SET reserved = reserved + :quantity WHERE id = :id AND quantity - reserved >= :quantity', ['quantity' => $take, 'id' => $stock['id']]);
                if (1 !== $changed) {
                    throw new ApiProblem(409, 'INSUFFICIENT_STOCK', 'Недостатньо залишку.');
                }
                $this->db->insert('stock_reservation', ['id' => Uuid::v7()->toRfc4122(), 'order_id' => $order->id, 'stock_item_id' => $stock['id'], 'quantity' => $take, 'status' => 'active']);
                $remaining -= $take;
            }
            if ($remaining > 0) {
                throw new ApiProblem(409, 'INSUFFICIENT_STOCK', 'Недостатньо залишку для SKU '.$item->sku.'.');
            }
        }
        $this->db->insert('payment', get_object_vars($this->payments->create($order->id, $order->total_minor, $order->currency)));
        $this->appendHistory($order->id, null, 'pending_payment', $order->user_id);

        return $this->order($order->number);
    }

    public function order(string $number, bool $lock = false): \stdClass
    {
        $order = $this->one('SELECT to_jsonb(o)::text FROM shop_order o WHERE number = :number'.($lock ? ' FOR UPDATE' : ''), (object) ['number' => $number]);
        $order->items = $this->many('SELECT to_jsonb(i)::text FROM order_item i WHERE order_id = :id ORDER BY id', (object) ['id' => $order->id])->items;
        $order->payment = $this->one('SELECT to_jsonb(p)::text FROM payment p WHERE order_id = :id', (object) ['id' => $order->id]);

        return $order;
    }

    public function orders(?string $userId, int $page, int $size): \stdClass
    {
        if ($page < 1 || $page > 100000 || $size < 1 || $size > 100) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна пагінація.');
        }
        $where = null === $userId ? '' : ' WHERE user_id = :user';
        $params = null === $userId ? new \stdClass() : (object) ['user' => $userId];
        $result = $this->many('SELECT (to_jsonb(o) - \'request_hash\' - \'idempotency_key\')::text FROM shop_order o'.$where.' ORDER BY placed_at DESC, id DESC LIMIT '.$size.' OFFSET '.(($page - 1) * $size), $params);
        $result->total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM shop_order'.$where, get_object_vars($params));
        $result->page = $page;
        $result->page_size = $size;

        return $result;
    }

    public function history(string $orderId): \stdClass
    {
        return $this->many('SELECT to_jsonb(h)::text FROM order_status_history h WHERE order_id = :id ORDER BY created_at, id', (object) ['id' => $orderId]);
    }

    public function transition(\stdClass $order, string $next, ?string $actorId): void
    {
        if ('paid' === $next || 'cancelled' === $next) {
            $rows = $this->db->fetchAllAssociative('SELECT r.id, r.stock_item_id, r.quantity, r.status FROM stock_reservation r JOIN stock_item s ON s.id = r.stock_item_id WHERE r.order_id = :id ORDER BY s.variant_id, s.warehouse_id FOR UPDATE OF s, r', ['id' => $order->id]);
            foreach ($rows as $row) {
                $quantity = (int) $row['quantity'];
                if ('active' === $row['status']) {
                    $sql = 'paid' === $next ? 'UPDATE stock_item SET reserved = reserved - :q, quantity = quantity - :q WHERE id = :id AND reserved >= :q AND quantity >= :q' : 'UPDATE stock_item SET reserved = reserved - :q WHERE id = :id AND reserved >= :q';
                    $changed = $this->db->executeStatement($sql, ['q' => $quantity, 'id' => $row['stock_item_id']]);
                    if (1 !== $changed) {
                        throw new \LogicException('Reservation invariant violated.');
                    }
                    $this->db->update('stock_reservation', ['status' => 'paid' === $next ? 'consumed' : 'released'], ['id' => $row['id']]);
                } elseif ('consumed' === $row['status'] && 'cancelled' === $next) {
                    $this->db->executeStatement('UPDATE stock_item SET quantity = quantity + :q WHERE id = :id', ['q' => $quantity, 'id' => $row['stock_item_id']]);
                    $this->db->update('stock_reservation', ['status' => 'returned'], ['id' => $row['id']]);
                }
            }
            $this->db->update('payment', ['status' => 'paid' === $next ? 'simulated_paid' : 'cancelled'], ['order_id' => $order->id]);
        }
        $this->db->update('shop_order', ['status' => $next], ['id' => $order->id]);
        $this->appendHistory($order->id, $order->status, $next, $actorId);
    }

    public function expiredOrders(int $limit): \stdClass
    {
        return $this->many("SELECT jsonb_build_object('number', number)::text FROM shop_order WHERE status = 'pending_payment' AND expires_at <= CURRENT_TIMESTAMP ORDER BY expires_at LIMIT ".max(1, min(1000, $limit)), new \stdClass());
    }

    private function appendHistory(string $id, ?string $from, string $to, ?string $actor): void
    {
        $this->db->insert('order_status_history', ['id' => Uuid::v7()->toRfc4122(), 'order_id' => $id, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor, 'created_at' => $this->now()]);
    }

    private function one(string $sql, \stdClass $parameters): \stdClass
    {
        $value = $this->db->fetchOne($sql, get_object_vars($parameters));
        if (!is_string($value)) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Запис не знайдено.');
        }
        $result = json_decode($value, false, 64, JSON_THROW_ON_ERROR);
        if (!$result instanceof \stdClass) {
            throw new \LogicException('Expected object.');
        }

        return $result;
    }

    private function many(string $sql, \stdClass $parameters): \stdClass
    {
        return (object) ['items' => array_map(static fn (string $json): mixed => json_decode($json, false, 64, JSON_THROW_ON_ERROR), $this->db->fetchFirstColumn($sql, get_object_vars($parameters)))];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }
}
