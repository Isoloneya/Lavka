<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\Identity\Application\CustomerGroups;
use App\Identity\Application\RegisterUser;
use App\Identity\Infrastructure\Persistence\UserRepository;
use App\Identity\Infrastructure\Security\User;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AuthController extends AbstractController
{
    #[Route('/api/v1/admin/users', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function users(Request $request, UserRepository $users): JsonResponse
    {
        return $this->json($users->page($request->query->getInt('page', 1), $request->query->getInt('page_size', 20)));
    }

    #[Route('/api/v1/auth/register', methods: ['POST'])]
    public function register(Request $request, RegisterUser $register, RateLimiterFactory $registrationLimiter): JsonResponse
    {
        if (!$registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Забагато спроб реєстрації.');
        }

        return $this->json($register->register(Input::fromJson($request->getContent()))->profile(), 201);
    }

    #[Route('/api/v1/auth/login', name: 'api_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Handled by security firewall.');
    }

    #[Route('/api/v1/me', methods: ['GET'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new ApiProblem(401, 'UNAUTHENTICATED', 'Потрібна автентифікація.');
        }

        return $this->json($user->profile());
    }

    #[Route('/api/v1/admin/users/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(string $id, Request $request, UserRepository $users, CustomerGroups $groups): JsonResponse
    {
        $input = Input::fromJson($request->getContent());
        $input->only('role', 'customer_group');
        $user = $users->find($id);
        if ($input->has('role')) {
            $user->role = $input->choice('role', 'ROLE_CUSTOMER', 'ROLE_MANAGER', 'ROLE_ADMIN');
        }
        if ($input->has('customer_group')) {
            $user->customerGroup = null === $input->data->customer_group ? null : $input->text('customer_group', 64);
            if (null !== $user->customerGroup) {
                $groups->requireCode($user->customerGroup);
            }
        }
        $users->save($user);

        return $this->json($user->profile());
    }
}
