<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof ContainerInterface) {
    throw new LogicException('Test container unavailable.');
}
$db = $container->get(Connection::class);
if (!$db instanceof Connection) {
    throw new LogicException('Test connection unavailable.');
}
$email = 'smoke-'.Uuid::v7()->toRfc4122().'@example.com';
$request = static function (string $method, string $path, ?stdClass $body = null, ?string $token = null): stdClass {
    if (!in_array($method, ['GET', 'POST'], true)) {
        throw new LogicException('Unsupported smoke request method.');
    }
    $curl = curl_init('http://lavka-production-smoke'.$path);
    if (false === $curl) {
        throw new RuntimeException('Cannot initialize HTTP client.');
    }
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (null !== $token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if (null !== $body) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Production HTTP check failed: '.$method.' '.$path.' status '.$status);
    }
    $data = json_decode($response, false, 64, JSON_THROW_ON_ERROR);
    if (!$data instanceof stdClass) {
        throw new RuntimeException('Invalid JSON response.');
    }

    return $data;
};
try {
    $request('GET', '/health');
    $request('GET', '/api/v1/products');
    $user = $request('POST', '/api/v1/auth/register', (object) ['email' => $email, 'password' => 'Production-smoke-123']);
    $login = $request('POST', '/api/v1/auth/login', (object) ['email' => $email, 'password' => 'Production-smoke-123']);
    $profile = $request('GET', '/api/v1/me', null, $login->token);
    if ($profile->id !== $user->id || $profile->email !== $email) {
        throw new RuntimeException('Production authentication failed.');
    }
    $graphql = $request('POST', '/api/graphql', (object) ['query' => '{ products(page_size: 1) { total } }']);
    if (isset($graphql->errors)) {
        throw new RuntimeException('Production GraphQL failed.');
    }
    echo "Production health, catalog, registration, JWT and GraphQL checks passed.\n";
} finally {
    $db->delete('app_user', ['email' => $email]);
    $kernel->shutdown();
}
