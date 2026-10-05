<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\ApiProblem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class ApiGuardListener
{
    public function __construct(private RateLimiterFactoryInterface $commerceLimiter)
    {
    }

    #[AsEventListener(event: 'kernel.request', priority: 8)]
    public function request(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        if (strlen($request->getContent()) > 1048576) {
            throw new ApiProblem(413, 'PAYLOAD_TOO_LARGE', 'Тіло запиту перевищує 1 MiB.');
        }
        if (preg_match('#^/api/(graphql|v1/(carts|checkout|pricing))#', $request->getPathInfo()) && !$this->commerceLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Забагато запитів. Спробуйте пізніше.');
        }
    }

    #[AsEventListener(event: 'kernel.response')]
    public function response(ResponseEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
        $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
        $event->getResponse()->headers->set('X-Content-Type-Options', 'nosniff');
    }
}
