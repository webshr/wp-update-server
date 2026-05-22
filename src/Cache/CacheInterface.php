<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Cache;

interface CacheInterface
{
    public function get(string $namespace, string $key): mixed;

    public function set(string $namespace, string $key, mixed $value, int $ttl): void;

    public function delete(string $namespace, string $key): void;

    public function clear(?string $namespace = null): void;
}
