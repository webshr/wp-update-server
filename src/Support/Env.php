<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Support;

final class Env
{
    public static function string(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on'  => true,
            '0', 'false', 'no', 'off' => false,
            default                   => $default,
        };
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = getenv($key);

        if ($value === false || ! is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    public static function list(string $key, array $default = []): array
    {
        $value = getenv($key);

        if ($value === false || trim($value) === '') {
            return array_values(array_filter($default, static fn (string $item): bool => $item !== ''));
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
