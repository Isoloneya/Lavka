<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Infrastructure\Persistence\UserRepository;
use App\Identity\Infrastructure\Security\User;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class RegisterUser
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private ValidatorInterface $validator,
    ) {
    }

    public function register(Input $input): User
    {
        $input->only('email', 'password');
        $email = strtolower($input->text('email', 254));
        if (count($this->validator->validate($email, new Email())) > 0) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректний email.', 'email');
        }

        $password = $input->data->password ?? null;
        if (!is_string($password) || mb_strlen($password) < 8 || strlen($password) > 72) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Пароль має містити щонайменше 8 символів і не більше 72 байтів.', 'password');
        }

        $user = new User(Uuid::v7()->toRfc4122(), $email);
        $user->changePassword($this->hasher->hashPassword($user, $password));
        $this->users->save($user);

        return $user;
    }
}
