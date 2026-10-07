<?php

declare(strict_types=1);

namespace App\Storefront\Infrastructure;

use App\Cart\Application\CartService;
use App\Identity\Infrastructure\Security\User;
use App\Order\Application\OrderService;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use App\Shared\Domain\Exception\DomainError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShoppingController extends AbstractController
{
    public function __construct(private readonly CartService $carts, private readonly OrderService $orders, private readonly ShoppingSession $shopping)
    {
    }

    #[Route('/cart', name: 'storefront_cart', methods: ['GET'])]
    public function cart(Request $request): Response
    {
        $cart = null;
        $error = null;
        if ('' !== $this->shopping->token($request)) {
            try {
                $cart = $this->carts->get($this->shopping->token($request), $this->customer()?->id, $this->customer()?->customerGroup);
                if ('active' !== $cart->status) {
                    $cart = null;
                }
            } catch (ApiProblem|DomainError $problem) {
                $error = $problem->getMessage();
            }
        }

        return $this->render('storefront/cart.html.twig', ['cart' => $cart, 'error' => $error, 'csrf' => $this->shopping->csrf($request)]);
    }

    #[Route('/cart/add', name: 'storefront_cart_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->shopping->validate($request);
        try {
            $token = $this->shopping->token($request);
            $cart = '' === $token ? null : $this->carts->access($token, $this->customer()?->id);
            if (null === $cart || 'active' !== $cart->status) {
                $cart = $this->carts->create(new Input((object) ['currency' => 'UAH']), $this->customer()?->id);
                $token = $cart->token;
                $request->getSession()->set('shopping_cart', $token);
            }
            $this->carts->change($token, $this->customer()?->id, $this->customer()?->customerGroup, 'add', new Input((object) ['sku' => $request->request->getString('sku'), 'quantity' => $request->request->getInt('quantity', 1)]));
            $this->addFlash('success', 'Товар додано до кошика.');
        } catch (ApiProblem|DomainError $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('storefront_cart', [], 303);
    }

    #[Route('/cart/change', name: 'storefront_cart_change', methods: ['POST'])]
    public function change(Request $request): Response
    {
        $this->shopping->validate($request);
        $operation = $request->request->getString('operation');
        if ('reset' === $operation) {
            $request->getSession()->remove('shopping_cart');

            return $this->redirectToRoute('storefront_cart', [], 303);
        }
        if (!in_array($operation, ['quantity', 'remove', 'coupon', 'clear_coupon'], true)) {
            throw $this->createNotFoundException();
        }
        $data = match ($operation) {
            'quantity' => (object) ['quantity' => $request->request->getInt('quantity')],
            'coupon' => (object) ['coupon_code' => $request->request->getString('coupon_code')],
            default => new \stdClass(),
        };
        try {
            $this->carts->change($this->shopping->token($request), $this->customer()?->id, $this->customer()?->customerGroup, $operation, new Input($data), $request->request->getString('item_id'));
        } catch (ApiProblem|DomainError $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('storefront_cart', [], 303);
    }

    #[Route('/checkout', name: 'storefront_checkout', methods: ['GET', 'POST'])]
    public function checkout(Request $request): Response
    {
        $session = $request->getSession();
        $token = $this->shopping->token($request);
        $key = $session->get('shopping_checkout_'.hash('sha256', $token));
        if (!is_string($key)) {
            $key = bin2hex(random_bytes(24));
            $session->set('shopping_checkout_'.hash('sha256', $token), $key);
        }
        $values = [];
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $this->shopping->validate($request);
            foreach (['email', 'recipient', 'phone', 'city', 'address', 'country', 'shipping_method'] as $field) {
                $values[$field] = $request->request->getString($field);
            }
            try {
                if (!hash_equals($key, $request->request->getString('checkout_key'))) {
                    throw new ApiProblem(409, 'CHECKOUT_CHANGED', 'Кошик змінився. Перевірте склад і надішліть форму ще раз.');
                }
                $order = $this->orders->checkout($token, $key, new Input((object) [
                    'email' => $values['email'], 'shipping_method' => $values['shipping_method'],
                    'shipping_address' => (object) array_intersect_key($values, array_flip(['recipient', 'phone', 'city', 'address', 'country'])),
                ]), $this->customer()?->id, $this->customer()?->customerGroup);
                $session->set('shopping_order_'.$order->number, $token);

                return $this->redirectToRoute('storefront_order', ['number' => $order->number], 303);
            } catch (ApiProblem|DomainError $problem) {
                $error = $problem->getMessage();
                $status = $problem instanceof ApiProblem ? $problem->status : 409;
            }
        }
        try {
            $cart = $this->carts->get($token, $this->customer()?->id, $this->customer()?->customerGroup);
            if ('active' !== $cart->status || [] === $cart->items) {
                return $this->redirectToRoute('storefront_cart', [], 303);
            }
        } catch (ApiProblem|DomainError $problem) {
            $this->addFlash('error', $problem->getMessage());

            return $this->redirectToRoute('storefront_cart', [], 303);
        }

        return $this->render('storefront/checkout.html.twig', ['cart' => $cart, 'values' => $values, 'error' => $error, 'csrf' => $this->shopping->csrf($request), 'checkout_key' => $key], new Response(status: $status));
    }

    #[Route('/order/{number}', name: 'storefront_order', methods: ['GET'])]
    public function order(string $number, Request $request): Response
    {
        $token = $request->getSession()->get('shopping_order_'.$number);
        if (!is_string($token) && null === $this->customer()) {
            throw $this->createNotFoundException();
        }
        try {
            $order = $this->orders->get($number, $this->customer()?->id, false, is_string($token) ? $token : '');
        } catch (ApiProblem $error) {
            if (404 !== $error->status) {
                throw $error;
            }

            throw $this->createNotFoundException();
        }

        return $this->render('storefront/order.html.twig', ['order' => $order]);
    }

    private function customer(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
