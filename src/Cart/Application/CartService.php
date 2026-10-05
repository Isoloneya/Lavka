<?php

declare(strict_types=1);

namespace App\Cart\Application;

use App\Pricing\Application\PricingService;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use App\Shared\Domain\CommerceRepository;

final readonly class CartService
{
    public function __construct(private CommerceRepository $repository, private PricingService $pricing)
    {
    }

    public function create(Input $input, ?string $userId): \stdClass
    {
        $input->only('currency');
        $token = bin2hex(random_bytes(32));
        $cart = $this->repository->createCart(hash('sha256', $token), $userId, $input->currency());
        $result = $this->view($cart, null);
        $result->token = $token;

        return $result;
    }

    public function access(string $token, ?string $userId, bool $lock = false): \stdClass
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Кошик не знайдено.');
        }
        $cart = $this->repository->cart(hash('sha256', $token), $lock);
        if (null !== $cart->user_id && $cart->user_id !== $userId) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Кошик не знайдено.');
        }

        return $cart;
    }

    public function get(string $token, ?string $userId, ?string $group): \stdClass
    {
        return $this->repository->transaction(fn (): \stdClass => $this->view($this->access($token, $userId, true), $group));
    }

    public function change(string $token, ?string $userId, ?string $group, string $operation, Input $input, ?string $itemId = null): \stdClass
    {
        return $this->repository->transaction(function () use ($token, $userId, $group, $operation, $input, $itemId): \stdClass {
            $cart = $this->access($token, $userId, true);
            $this->assertActive($cart);
            switch ($operation) {
                case 'add':
                    $input->only('sku', 'quantity');
                    $this->repository->putItem($cart->id, $input->text('sku', 64), $input->integer('quantity', 1, 100));
                    break;
                case 'quantity':
                    $input->only('quantity');
                    $this->repository->putItem($cart->id, '', $input->integer('quantity', 1, 100), $itemId);
                    break;
                case 'remove':
                    $this->repository->removeItem($cart->id, $itemId ?? '');
                    break;
                case 'coupon':
                    $input->only('coupon_code');
                    $cart->coupon_code = $input->text('coupon_code', 64);
                    break;
                case 'clear_coupon':
                    $cart->coupon_code = null;
                    break;
                default:
                    throw new \LogicException('Unknown cart operation.');
            }
            $this->repository->saveCart($cart);

            return $this->view($cart, $group);
        });
    }

    public function assertActive(\stdClass $cart): void
    {
        if ('active' !== $cart->status) {
            throw new ApiProblem(409, 'CART_CLOSED', 'Кошик уже оформлено.');
        }
    }

    public function view(\stdClass $cart, ?string $group): \stdClass
    {
        $items = $this->repository->items($cart->id)->items;
        $pricing = $this->pricing->calculate(new Input((object) ['currency' => $cart->currency, 'coupon_code' => $cart->coupon_code, 'items' => array_map(static fn (\stdClass $item): \stdClass => (object) ['sku' => $item->sku, 'quantity' => $item->quantity], $items)]), $group);
        $pricing->shipping = 0;

        return (object) ['id' => $cart->id, 'currency' => $cart->currency, 'status' => $cart->status, 'coupon_code' => $cart->coupon_code, 'items' => $items, 'pricing' => $pricing];
    }
}
