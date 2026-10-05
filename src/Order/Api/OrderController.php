<?php

declare(strict_types=1);

namespace App\Order\Api;

use App\Identity\Infrastructure\Security\User;
use App\Order\Application\OrderService;
use App\Shared\Application\Input;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OrderController extends AbstractController
{
    #[Route('/api/v1/checkout', methods: ['POST'])]
    public function checkout(Request $request, OrderService $orders): JsonResponse
    {
        $user = $this->getUser();

        return $this->json($orders->checkout($request->headers->get('X-Cart-Token') ?? '', $request->headers->get('Idempotency-Key') ?? '', Input::fromJson($request->getContent()), $user instanceof User ? $user->id : null, $user instanceof User ? $user->customerGroup : null), 201);
    }

    #[Route('/api/v1/orders', methods: ['GET'], defaults: ['admin' => false])]
    #[Route('/api/v1/admin/orders', methods: ['GET'], defaults: ['admin' => true])]
    public function listing(Request $request, OrderService $orders, bool $admin): JsonResponse
    {
        $user = $this->getUser();

        return $this->json($orders->listing($user instanceof User ? $user->id : null, $admin && $this->isGranted('ROLE_MANAGER'), $request->query->getInt('page', 1), $request->query->getInt('page_size', 20)));
    }

    #[Route('/api/v1/orders/{number}', methods: ['GET'])]
    public function get(string $number, Request $request, OrderService $orders): JsonResponse
    {
        $user = $this->getUser();

        return $this->json($orders->get($number, $user instanceof User ? $user->id : null, $this->isGranted('ROLE_MANAGER'), $request->headers->get('X-Cart-Token') ?? ''));
    }

    #[Route('/api/v1/orders/{number}/history', methods: ['GET'])]
    public function history(string $number, Request $request, OrderService $orders): JsonResponse
    {
        $user = $this->getUser();

        return $this->json($orders->history($number, $user instanceof User ? $user->id : null, $this->isGranted('ROLE_MANAGER'), $request->headers->get('X-Cart-Token') ?? ''));
    }

    #[Route('/api/v1/orders/{number}/cancel', methods: ['POST'])]
    public function cancel(string $number, Request $request, OrderService $orders): JsonResponse
    {
        $user = $this->getUser();

        return $this->json($orders->transition($number, 'cancel', $user instanceof User ? $user->id : null, false, $request->headers->get('X-Cart-Token') ?? ''));
    }

    #[Route('/api/v1/admin/orders/{number}/transitions/{name}', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function transition(string $number, string $name, OrderService $orders): JsonResponse
    {
        $user = $this->getUser();

        return $this->json($orders->transition($number, $name, $user instanceof User ? $user->id : null, true));
    }
}
