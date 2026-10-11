<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Hosting;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[When(env: 'vercel')]
#[AsController]
final readonly class MaintenanceController
{
    public function __construct(private Maintenance $maintenance, #[Autowire(env: 'CRON_SECRET')] private string $secret)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (strlen($this->secret) < 32 || !hash_equals('Bearer '.$this->secret, $request->headers->get('Authorization') ?? '')) {
            return new JsonResponse(['error' => 'Unauthorized'], 401, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse(['expired_orders' => $this->maintenance->run(true)], 200, ['Cache-Control' => 'no-store']);
    }
}
