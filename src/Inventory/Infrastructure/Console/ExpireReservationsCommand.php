<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Console;

use App\Order\Application\OrderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:reservations:expire', description: 'Release expired order reservations')]
final class ExpireReservationsCommand extends Command
{
    public function __construct(private readonly OrderService $orders)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln((string) $this->orders->expire(1000));

        return Command::SUCCESS;
    }
}
