<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $id;

    #[ORM\Column(length: 254, unique: true)]
    public string $email;

    #[ORM\Column(name: 'password_hash')]
    private string $passwordHash = '';

    #[ORM\Column(length: 30)]
    public string $role = 'ROLE_CUSTOMER';

    #[ORM\Column(name: 'customer_group', length: 64, nullable: true)]
    public ?string $customerGroup = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $id, string $email)
    {
        $this->id = $id;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('User email cannot be empty.');
        }

        return $this->email;
    }

    public function getRoles(): array
    {
        return [$this->role];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function changePassword(string $hash): void
    {
        $this->passwordHash = $hash;
    }

    public function eraseCredentials(): void
    {
    }

    public function profile(): \stdClass
    {
        return (object) ['id' => $this->id, 'email' => $this->email, 'roles' => $this->getRoles(), 'customer_group' => $this->customerGroup];
    }
}
