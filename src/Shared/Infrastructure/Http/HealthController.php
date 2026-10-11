<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    public function __construct(private Connection $connection, private string $redisUrl)
    {
    }

    #[Route('/health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $database = false;
        $cache = '' === $this->redisUrl ? null : false;
        try {
            $database = 1 === (int) $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable) {
        }
        try {
            if (null === $cache) {
                return new JsonResponse(['status' => $database ? 'ok' : 'degraded', 'database' => $database, 'redis' => null], $database ? 200 : 503);
            }
            $redis = new \Redis();
            $host = parse_url($this->redisUrl, PHP_URL_HOST);
            $port = parse_url($this->redisUrl, PHP_URL_PORT) ?? 6379;
            if (is_string($host) && is_int($port) && $redis->connect($host, $port, 1.0)) {
                $cache = true === $redis->ping();
                $redis->close();
            }
        } catch (\Throwable) {
        }

        return new JsonResponse(['status' => $database && $cache ? 'ok' : 'degraded', 'database' => $database, 'redis' => $cache], $database && $cache ? 200 : 503);
    }
}
