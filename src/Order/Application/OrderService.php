<?php

declare(strict_types=1);

namespace App\Order\Application;

use App\Cart\Application\CartService;
use App\Order\Domain\OrderState;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use App\Shared\Domain\CommerceRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class OrderService
{
    public function __construct(
        private CommerceRepository $repository,
        private CartService $carts,
        #[Autowire(service: 'state_machine.order')] private WorkflowInterface $workflow,
        #[Autowire('%env(int:CART_RESERVATION_TTL)%')] private int $ttl,
    ) {
    }

    public function checkout(string $token, string $key, Input $input, ?string $userId, ?string $group): \stdClass
    {
        $input->only('email', 'shipping_address', 'shipping_method');
        if (1 !== preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $key)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Idempotency-Key має містити 16–128 символів.', 'Idempotency-Key');
        }
        $email = strtolower($input->text('email', 254));
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректний email.', 'email');
        }
        $address = new Input($input->object('shipping_address'));
        $address->only('country', 'city', 'address', 'recipient', 'phone');
        $normalized = (object) ['country' => strtoupper($address->text('country', 2)), 'city' => $address->text('city', 100), 'address' => $address->text('address'), 'recipient' => $address->text('recipient', 100), 'phone' => $address->text('phone', 30)];
        if (1 !== preg_match('/^[A-Z]{2}$/', $normalized->country) || 1 !== preg_match('/^\+?[0-9 ()-]{7,30}$/', $normalized->phone)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна країна або телефон.', 'shipping_address');
        }
        $method = $input->choice('shipping_method', 'pickup', 'stub_delivery');
        $requestHash = hash('sha256', json_encode([$token, $userId, $email, $normalized, $method], JSON_THROW_ON_ERROR));

        return $this->repository->transaction(function () use ($token, $key, $userId, $group, $email, $normalized, $method, $requestHash): \stdClass {
            $this->repository->lockKey('checkout:'.$key);
            $cart = $this->carts->access($token, $userId, true);
            $existing = $this->repository->orderByKey($key);
            if (null !== $existing) {
                if (!hash_equals($existing->request_hash, $requestHash)) {
                    throw new ApiProblem(409, 'IDEMPOTENCY_CONFLICT', 'Ключ уже використано для іншого запиту.');
                }

                return $this->publicOrder($existing);
            }
            $this->carts->assertActive($cart);
            $snapshot = $this->carts->view($cart, $group);
            if ([] === $snapshot->items) {
                throw new ApiProblem(400, 'EMPTY_CART', 'Кошик порожній.');
            }
            $items = $this->repository->items($cart->id);
            foreach ($items->items as $item) {
                $item->unit_price_minor = $snapshot->pricing->unit_prices->{$item->sku};
            }
            $order = (object) ['cart_id' => $cart->id, 'user_id' => $userId, 'email' => $email, 'currency' => $cart->currency, 'subtotal_minor' => $snapshot->pricing->subtotal, 'discount_minor' => $snapshot->pricing->discount, 'shipping_minor' => 0, 'total_minor' => $snapshot->pricing->total, 'pricing_snapshot' => $snapshot->pricing, 'shipping_address' => $normalized, 'shipping_method' => $method, 'idempotency_key' => $key, 'request_hash' => $requestHash];
            if ($this->ttl < 60 || $this->ttl > 86400) {
                throw new \LogicException('CART_RESERVATION_TTL must be between 60 and 86400.');
            }
            $created = $this->repository->createOrder($order, $items, $this->ttl);
            $cart->status = 'converted';
            $this->repository->saveCart($cart);

            return $this->publicOrder($created);
        });
    }

    public function get(string $number, ?string $userId, bool $staff, string $cartToken = ''): \stdClass
    {
        $order = $this->repository->order($number);
        $this->authorize($order, $userId, $staff, $cartToken);

        return $this->publicOrder($order);
    }

    public function listing(?string $userId, bool $staff, int $page, int $size): \stdClass
    {
        if (!$staff && null === $userId) {
            throw new ApiProblem(401, 'UNAUTHENTICATED', 'Потрібна автентифікація.');
        }

        return $this->repository->orders($staff ? null : $userId, $page, $size);
    }

    public function history(string $number, ?string $userId, bool $staff, string $token): \stdClass
    {
        $order = $this->repository->order($number);
        $this->authorize($order, $userId, $staff, $token);

        return $this->repository->history($order->id);
    }

    public function transition(string $number, string $name, ?string $userId, bool $staff, string $token = ''): \stdClass
    {
        return $this->repository->transaction(function () use ($number, $name, $userId, $staff, $token): \stdClass {
            $order = $this->repository->order($number, true);
            $this->authorize($order, $userId, $staff, $token);
            if (!$staff && ('cancel' !== $name || 'pending_payment' !== $order->status)) {
                throw new ApiProblem(403, 'ACCESS_DENIED', 'Цей перехід доступний лише персоналу.');
            }
            if ('pay' === $name && new \DateTimeImmutable($order->expires_at) <= new \DateTimeImmutable()) {
                throw new ApiProblem(409, 'RESERVATION_EXPIRED', 'Термін резерву минув.');
            }
            $state = new OrderState($order->status);
            if (!$this->workflow->can($state, $name)) {
                throw new ApiProblem(409, 'INVALID_TRANSITION', 'Недопустимий перехід стану.');
            }
            $this->workflow->apply($state, $name);
            $this->repository->transition($order, $state->status, $userId);

            return $this->publicOrder($this->repository->order($number));
        });
    }

    public function expire(int $limit = 100): int
    {
        $count = 0;
        foreach ($this->repository->expiredOrders($limit)->items as $candidate) {
            $count += $this->repository->transaction(function () use ($candidate): int {
                $order = $this->repository->order($candidate->number, true);
                if ('pending_payment' !== $order->status || new \DateTimeImmutable($order->expires_at) > new \DateTimeImmutable()) {
                    return 0;
                }
                $this->repository->transition($order, 'cancelled', null);

                return 1;
            });
        }

        return $count;
    }

    private function authorize(\stdClass $order, ?string $userId, bool $staff, string $token): void
    {
        if ($staff || (null !== $userId && $order->user_id === $userId)) {
            return;
        }
        if (null === $order->user_id && '' !== $token && $this->carts->access($token, $userId)->id === $order->cart_id) {
            return;
        }
        throw new ApiProblem(404, 'NOT_FOUND', 'Замовлення не знайдено.');
    }

    private function publicOrder(\stdClass $order): \stdClass
    {
        unset($order->idempotency_key, $order->request_hash);
        $order->payment->test_mode = true;

        return $order;
    }
}
