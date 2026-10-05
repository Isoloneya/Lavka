<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener(event: 'kernel.response')]
final class ErrorResponseListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $status = $response->getStatusCode();
        if ($status < 400 || !str_starts_with($event->getRequest()->getPathInfo(), '/api/') || str_contains($response->headers->get('Content-Type') ?? '', 'application/problem+json')) {
            return;
        }
        $code = match ($status) {
            401 => 'UNAUTHENTICATED',
            403 => 'ACCESS_DENIED',
            429 => 'RATE_LIMITED',
            default => 'HTTP_'.$status,
        };
        $title = JsonResponse::$statusTexts[$status] ?? 'Error';
        $event->setResponse(new JsonResponse([
            'type' => 'urn:lavka:error:'.strtolower($code),
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $title,
            'violations' => [],
        ], $status, ['Content-Type' => 'application/problem+json']));
    }
}
