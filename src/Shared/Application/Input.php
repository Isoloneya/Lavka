<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use Symfony\Component\Uid\Uuid;

final readonly class Input
{
    public function __construct(public \stdClass $data)
    {
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'INVALID_JSON', 'Некоректний JSON.');
        }

        if (!$data instanceof \stdClass) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Очікується JSON-об’єкт.');
        }

        return new self($data);
    }

    public function only(string ...$fields): void
    {
        foreach (get_object_vars($this->data) as $field => $value) {
            if (!in_array($field, $fields, true)) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Невідоме поле.', $field);
            }
        }
    }

    public function has(string $field): bool
    {
        return property_exists($this->data, $field);
    }

    public function text(string $field, int $max = 255, ?string $default = null): string
    {
        $value = $this->has($field) ? $this->data->{$field} : $default;
        if (!is_string($value) || '' === trim($value) || mb_strlen($value) > $max) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректний текст.', $field);
        }

        return trim($value);
    }

    public function integer(string $field, int $min = 0, int $max = PHP_INT_MAX, ?int $default = null): int
    {
        $value = $this->has($field) ? $this->data->{$field} : $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Число поза допустимим діапазоном.', $field);
        }

        return $value;
    }

    public function boolean(string $field, bool $default = true): bool
    {
        $value = $this->has($field) ? $this->data->{$field} : $default;
        if (!is_bool($value)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Очікується boolean.', $field);
        }

        return $value;
    }

    public function object(string $field): \stdClass
    {
        $value = $this->has($field) ? $this->data->{$field} : new \stdClass();
        if (!$value instanceof \stdClass) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Очікується JSON-об’єкт.', $field);
        }

        return $value;
    }

    public function uuid(string $field): string
    {
        $value = $this->text($field, 36);
        if (!Uuid::isValid($value)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректний UUID.', $field);
        }

        return Uuid::fromString($value)->toRfc4122();
    }

    public function nullableUuid(string $field): ?string
    {
        return null === ($this->data->{$field} ?? null) ? null : $this->uuid($field);
    }

    public function slug(string $field = 'slug'): string
    {
        $value = $this->text($field, 100);
        if (1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректний slug.', $field);
        }

        return $value;
    }

    public function currency(): string
    {
        $value = $this->text('currency', 3);
        try {
            Currency::of($value);
        } catch (UnknownCurrencyException) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Невідома валюта ISO 4217.', 'currency');
        }

        return $value;
    }

    public function choice(string $field, string ...$choices): string
    {
        $value = $this->text($field);
        if (!in_array($value, $choices, true)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Непідтримуване значення.', $field);
        }

        return $value;
    }

    public function date(string $field): ?string
    {
        if (null === ($this->data->{$field} ?? null)) {
            return null;
        }

        $value = $this->text($field, 40);
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);
        if (false === $date || $date->format(\DateTimeInterface::ATOM) !== $value) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Очікується дата ISO 8601 із часовим поясом.', $field);
        }

        return $date->format(\DateTimeInterface::ATOM);
    }
}
