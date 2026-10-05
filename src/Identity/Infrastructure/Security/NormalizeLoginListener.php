<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

#[AsEventListener(event: 'kernel.request', priority: 9)]
final class NormalizeLoginListener
{
    public function __construct(private readonly RateLimiterFactoryInterface $loginLimiter)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || '/api/v1/auth/login' !== $request->getPathInfo() || !$request->isMethod('POST')) {
            return;
        }
        $input = Input::fromJson($request->getContent());
        if (!$this->loginLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Забагато спроб входу.');
        }
        $input->only('email', 'password');
        $input->data->email = strtolower($input->text('email', 254));
        $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(), $request->cookies->all(), $request->files->all(), $request->server->all(), json_encode($input->data, JSON_THROW_ON_ERROR));
    }
}
