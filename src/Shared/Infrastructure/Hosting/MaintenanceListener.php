<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Hosting;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

#[When(env: 'vercel')]
#[AsEventListener(event: 'kernel.controller', priority: 10)]
final readonly class MaintenanceListener
{
    public function __construct(private Maintenance $maintenance)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if ($event->isMainRequest() && !in_array($event->getRequest()->getPathInfo(), ['/health', '/internal/maintenance'], true)) {
            $this->maintenance->run();
        }
    }
}
