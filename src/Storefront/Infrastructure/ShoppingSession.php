<?php

declare(strict_types=1);

namespace App\Storefront\Infrastructure;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ShoppingSession
{
    public function csrf(Request $request): string
    {
        $session = $request->getSession();
        $token = $session->get('shopping_csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $session->set('shopping_csrf', $token);
        }

        return $token;
    }

    public function validate(Request $request): void
    {
        $expected = $request->getSession()->get('shopping_csrf');
        if (!is_string($expected) || !hash_equals($expected, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Сторінка застаріла. Оновіть її та спробуйте ще раз.');
        }
    }

    public function token(Request $request): string
    {
        $token = $request->getSession()->get('shopping_cart');

        return is_string($token) ? $token : '';
    }
}
