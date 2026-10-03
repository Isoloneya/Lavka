<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\RegisterUser;
use App\Identity\Infrastructure\Persistence\UserRepository;
use App\Shared\Application\Input;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:create-staff', description: 'Створити менеджера або адміністратора')]
final class CreateStaffCommand extends Command
{
    public function __construct(private readonly RegisterUser $register, private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED);
        $this->addArgument('role', InputArgument::OPTIONAL, 'ROLE_MANAGER або ROLE_ADMIN', 'ROLE_MANAGER');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $role = $input->getArgument('role');
        if (!is_string($email) || !in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true)) {
            $io->error('Некоректний email або роль.');

            return Command::INVALID;
        }

        $password = $io->askHidden('Пароль');
        $user = $this->register->register(new Input((object) ['email' => $email, 'password' => $password]));
        $user->role = $role;
        $this->users->save($user);
        $io->success('Користувача створено: '.$user->id);

        return Command::SUCCESS;
    }
}
