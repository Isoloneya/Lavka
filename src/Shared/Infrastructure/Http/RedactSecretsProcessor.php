<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

#[AsMonologProcessor]
final readonly class RedactSecretsProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        foreach ($context as $key => $value) {
            $context[$key] = $this->redact($key, $value);
        }
        $extra = $record->extra;
        foreach ($extra as $key => $value) {
            $extra[$key] = $this->redact($key, $value);
        }

        return $record->with(message: $this->text($record->message), context: $context, extra: $extra);
    }

    private function redact(string|int $key, mixed $value): mixed
    {
        if (is_string($key) && preg_match('/password|passphrase|authorization|(^|[_-])token$|secret/i', $key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            foreach ($value as $nestedKey => $nestedValue) {
                $value[$nestedKey] = $this->redact($nestedKey, $nestedValue);
            }
        }

        return is_string($value) ? $this->text($value) : $value;
    }

    private function text(string $value): string
    {
        return preg_replace(['#(/carts/)[a-f0-9]{64}#i', '/Bearer\s+[A-Za-z0-9._-]+/i'], ['$1[redacted]', 'Bearer [redacted]'], $value) ?? '[redacted]';
    }
}
