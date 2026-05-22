<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Support;

final class Path
{
    public static function join(string ...$parts): string
    {
        $path = implode(DIRECTORY_SEPARATOR, array_map(static fn (string $part): string => trim($part, "/\\"), $parts));

        if (isset($parts[0]) && preg_match('/^[A-Za-z]:[\/\\\\]?$/', $parts[0]) === 1) {
            return rtrim($parts[0], "/\\") . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($parts, 1));
        }

        return $path;
    }

    public static function resolve(string $path, string $baseDir): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return rtrim($baseDir, "/\\") . DIRECTORY_SEPARATOR . ltrim($path, "/\\");
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
