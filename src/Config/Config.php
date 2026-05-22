<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Config;

final class Config
{
    /** @param array<string, mixed> $licenses */
    public function __construct(
        public readonly ServerConfig $server,
        public readonly PackageRegistry $packages,
        public readonly array $licenses = []
    ) {
    }
}
