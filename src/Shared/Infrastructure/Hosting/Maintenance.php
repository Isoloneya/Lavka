<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Hosting;

use App\Order\Application\OrderService;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Lock\LockFactory;

#[When(env: 'vercel')]
final readonly class Maintenance
{
    public function __construct(
        private OrderService $orders,
        private Connection $connection,
        private LockFactory $locks,
        #[Autowire(service: 'cache.app')] private CacheItemPoolInterface $cache,
    ) {
    }

    public function run(bool $force = false): int
    {
        $lock = $this->locks->createLock('demo-maintenance', 60);
        if (!$lock->acquire()) {
            return 0;
        }
        try {
            $item = $this->cache->getItem('maintenance.next');
            if (!$force && $item->isHit()) {
                return 0;
            }
            $count = $this->orders->expire(100);
            $this->connection->executeStatement('DELETE FROM sessions WHERE sess_id IN (SELECT sess_id FROM sessions WHERE sess_lifetime < :now LIMIT 1000)', ['now' => time()]);
            $this->connection->executeStatement('DELETE FROM cache_items WHERE item_id IN (SELECT item_id FROM cache_items WHERE item_lifetime + item_time <= :now LIMIT 1000)', ['now' => time()]);
            $this->cache->save($item->set(true)->expiresAfter(60));

            return $count;
        } finally {
            $lock->release();
        }
    }
}
