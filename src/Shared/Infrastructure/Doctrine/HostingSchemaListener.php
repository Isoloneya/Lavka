<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema, priority: 50)]
final class HostingSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        $sessions = $schema->createTable('sessions');
        $sessions->addColumn('sess_id', 'string', ['length' => 128]);
        $sessions->addColumn('sess_data', 'blob');
        $sessions->addColumn('sess_lifetime', 'integer');
        $sessions->addColumn('sess_time', 'integer');
        $sessions->setPrimaryKey(['sess_id']);
        $sessions->addIndex(['sess_lifetime'], 'sess_lifetime_idx');

        $cache = $schema->createTable('cache_items');
        $cache->addColumn('item_id', 'string', ['length' => 255]);
        $cache->addColumn('item_data', 'blob');
        $cache->addColumn('item_lifetime', 'integer', ['notnull' => false]);
        $cache->addColumn('item_time', 'integer');
        $cache->setPrimaryKey(['item_id']);

        $locks = $schema->createTable('lock_keys');
        $locks->addColumn('key_id', 'string', ['length' => 64]);
        $locks->addColumn('key_token', 'string', ['length' => 44]);
        $locks->addColumn('key_expiration', 'integer');
        $locks->setPrimaryKey(['key_id']);
    }
}
