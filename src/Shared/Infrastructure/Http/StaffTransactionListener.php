<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Identity\Infrastructure\Security\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final readonly class StaffTransactionListener
{
    public function __construct(private Connection $connection, private TokenStorageInterface $tokens)
    {
    }

    #[AsEventListener(event: 'kernel.controller')]
    public function begin(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $user = $this->tokens->getToken()?->getUser();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/v1/admin/') || $request->isMethodSafe() || !$user instanceof User || !in_array($user->role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true)) {
            return;
        }
        $this->connection->beginTransaction();
        $request->attributes->set('_staff_transaction', $user->id);
    }

    #[AsEventListener(event: 'kernel.response', priority: -10)]
    public function finish(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $actor = $request->attributes->get('_staff_transaction');
        if (!$event->isMainRequest() || !is_string($actor)) {
            return;
        }
        $request->attributes->remove('_staff_transaction');
        if ($event->getResponse()->getStatusCode() >= 400) {
            $this->connection->rollBack();

            return;
        }
        try {
            $changes = json_decode($request->getContent(), false);
            $this->connection->insert('audit_log', [
                'id' => Uuid::v7()->toRfc4122(),
                'actor_id' => $actor,
                'action' => $request->getMethod(),
                'resource' => $request->getPathInfo(),
                'changes' => json_encode($changes ?? new \stdClass(), JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ]);
            $this->connection->commit();
        } catch (\Throwable $error) {
            $this->connection->rollBack();

            throw $error;
        }
    }
}
