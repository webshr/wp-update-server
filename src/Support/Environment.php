<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Support;

use Dotenv\Dotenv;

final class Environment
{
    public static function load(string $rootDir): void
    {
        if (!is_file($rootDir . DIRECTORY_SEPARATOR . '.env')) {
            return;
        }

        Dotenv::createUnsafeImmutable($rootDir)->safeLoad();
    }
}
