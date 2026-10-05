<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Infrastructure\Http\RedactSecretsProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RedactSecretsProcessorTest extends TestCase
{
    public function testRedactsCartTokensCredentialsAndNestedRouteParameters(): void
    {
        $token = str_repeat('a', 64);
        $record = new LogRecord(new \DateTimeImmutable(), 'request', Level::Error, 'GET /api/v1/carts/'.$token, ['route_parameters' => ['token' => $token], 'headers' => ['Authorization' => 'Bearer signed-token'], 'password' => 'secret-value', 'request_id' => 'safe-id']);
        $result = (new RedactSecretsProcessor())($record);
        self::assertStringNotContainsString($token, $result->message);
        self::assertSame('[redacted]', $result->context['route_parameters']['token']);
        self::assertSame('[redacted]', $result->context['headers']['Authorization']);
        self::assertSame('[redacted]', $result->context['password']);
        self::assertSame('safe-id', $result->context['request_id']);
    }
}
