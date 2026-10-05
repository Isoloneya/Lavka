<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Infrastructure\Security\User;
use App\Shared\Application\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class UserRepository
{
    public function __construct(private EntityManagerInterface $manager)
    {
    }

    public function save(User $user): void
    {
        $this->manager->persist($user);
        $this->manager->flush();
    }

    public function find(string $id): User
    {
        if (!Uuid::isValid($id)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректний UUID.', 'id');
        }

        return $this->manager->find(User::class, $id) ?? throw new ApiProblem(404, 'NOT_FOUND', 'Користувача не знайдено.');
    }

    public function page(int $page, int $size): \stdClass
    {
        if ($page < 1 || $page > 100000 || $size < 1 || $size > 100) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна пагінація.');
        }
        $repository = $this->manager->getRepository(User::class);
        $users = $repository->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], $size, ($page - 1) * $size);

        return (object) ['items' => array_map(static fn (User $user): \stdClass => $user->profile(), $users), 'total' => $repository->count([]), 'page' => $page, 'page_size' => $size];
    }
}
