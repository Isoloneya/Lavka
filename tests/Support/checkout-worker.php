<?php

declare(strict_types=1);

use App\Kernel;
use App\Order\Application\OrderService;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof Psr\Container\ContainerInterface) {
    throw new LogicException('Test container unavailable.');
}
$orders = $container->get(OrderService::class);
if (!$orders instanceof OrderService) {
    throw new LogicException('OrderService unavailable.');
}
try {
    $order = $orders->checkout($argv[1], $argv[2], new Input((object) ['email' => 'concurrent@example.com', 'shipping_method' => 'pickup', 'shipping_address' => (object) ['country' => 'UA', 'city' => 'Kyiv', 'address' => 'Street 1', 'recipient' => 'Test', 'phone' => '+380501234567']]), null, null);
    echo json_encode(['status' => 201, 'number' => $order->number], JSON_THROW_ON_ERROR);
} catch (ApiProblem $error) {
    echo json_encode(['status' => $error->status, 'code' => $error->errorCode], JSON_THROW_ON_ERROR);
}
$kernel->shutdown();
