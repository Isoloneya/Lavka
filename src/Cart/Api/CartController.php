<?php

declare(strict_types=1);

namespace App\Cart\Api;

use App\Cart\Application\CartService;
use App\Identity\Infrastructure\Security\User;
use App\Shared\Application\Input;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CartController extends AbstractController
{
    #[Route('/api/v1/carts', methods: ['POST'])]
    public function create(Request $request, CartService $carts): JsonResponse
    {
        $user = $this->getUser();

        return $this->json($carts->create(Input::fromJson($request->getContent()), $user instanceof User ? $user->id : null), 201);
    }

    #[Route('/api/v1/carts/{token}', methods: ['GET'])]
    public function get(string $token, CartService $carts): JsonResponse
    {
        $user = $this->getUser();

        return $this->json($carts->get($token, $user instanceof User ? $user->id : null, $user instanceof User ? $user->customerGroup : null));
    }

    #[Route('/api/v1/carts/{token}/items', methods: ['POST'], defaults: ['operation' => 'add'])]
    #[Route('/api/v1/carts/{token}/items/{itemId}', methods: ['PATCH'], defaults: ['operation' => 'quantity'])]
    #[Route('/api/v1/carts/{token}/items/{itemId}', methods: ['DELETE'], defaults: ['operation' => 'remove'])]
    #[Route('/api/v1/carts/{token}/coupon', methods: ['PUT'], defaults: ['operation' => 'coupon'])]
    #[Route('/api/v1/carts/{token}/coupon', methods: ['DELETE'], defaults: ['operation' => 'clear_coupon'])]
    public function change(string $token, string $operation, Request $request, CartService $carts, ?string $itemId = null): JsonResponse
    {
        $user = $this->getUser();
        $input = $request->isMethod('DELETE') ? new Input(new \stdClass()) : Input::fromJson($request->getContent());

        return $this->json($carts->change($token, $user instanceof User ? $user->id : null, $user instanceof User ? $user->customerGroup : null, $operation, $input, $itemId));
    }
}
