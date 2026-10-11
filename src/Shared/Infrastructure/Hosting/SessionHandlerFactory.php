<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Hosting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

#[When(env: 'vercel')]
final readonly class SessionHandlerFactory
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(): PdoSessionHandler
    {
        $params = $this->connection->getParams();
        $params['driver'] = 'pdo_pgsql';
        $pdo = DriverManager::getConnection($params)->getNativeConnection();
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Vercel sessions require a PDO database connection.');
        }

        return new PdoSessionHandler($pdo, ['ttl' => 86400]);
    }
}
