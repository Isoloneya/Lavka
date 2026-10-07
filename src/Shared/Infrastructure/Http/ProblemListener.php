<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\ApiProblem;
use App\Shared\Domain\Exception\DomainError;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsEventListener(event: 'kernel.exception', priority: -64)]
final readonly class ProblemListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $error = $event->getThrowable();
        $status = 500;
        $code = 'INTERNAL_ERROR';
        $detail = 'Внутрішня помилка сервера.';
        $violations = [];

        if ($error instanceof ApiProblem) {
            $status = $error->status;
            $code = $error->errorCode;
            $detail = $error->getMessage();
            if (null !== $error->field) {
                $violations[] = ['propertyPath' => $error->field, 'message' => $detail];
            }
        } elseif ($error instanceof UniqueConstraintViolationException) {
            $status = 409;
            $code = 'ALREADY_EXISTS';
            $detail = 'Запис із таким унікальним значенням уже існує.';
        } elseif ($error instanceof ForeignKeyConstraintViolationException) {
            $status = 409;
            $code = 'REFERENCE_CONFLICT';
            $detail = 'Пов’язаний запис відсутній або використовується.';
        } elseif ($error instanceof DomainError) {
            $status = 409;
            $code = $error->errorCode();
            $detail = $error->getMessage();
        } elseif ($error instanceof AccessDeniedException) {
            $status = 403;
            $code = 'ACCESS_DENIED';
            $detail = 'Недостатньо прав.';
        } elseif ($error instanceof HttpExceptionInterface) {
            $status = $error->getStatusCode();
            $code = 'HTTP_'.$status;
            $detail = JsonResponse::$statusTexts[$status] ?? 'Помилка запиту.';
        }

        $requestId = bin2hex(random_bytes(16));
        if (500 === $status) {
            $this->logger->error('Unhandled API error', ['exception' => $error, 'request_id' => $requestId]);
        }

        $response = new JsonResponse([
            'type' => 'urn:lavka:error:'.strtolower($code),
            'title' => JsonResponse::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'violations' => $violations,
            'request_id' => $requestId,
        ], $status, ['Content-Type' => 'application/problem+json', 'X-Request-Id' => $requestId]);

        $event->setResponse($response);
    }
}
