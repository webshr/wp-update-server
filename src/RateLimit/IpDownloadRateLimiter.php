<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\RateLimit;

use Webshr\WpUpdateServer\Cache\CacheInterface;
use Webshr\WpUpdateServer\Http\Request;

final class IpDownloadRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 3600
    ) {
    }

    public function allow(string $slug, Request $request): bool
    {
        $key = sha1($slug . '|' . $request->clientIp . '|' . floor(time() / $this->windowSeconds));
        $count = (int) ($this->cache->get('rate-limit', $key) ?? 0);
        if ($count >= $this->limit) {
            return false;
        }

        $this->cache->set('rate-limit', $key, $count + 1, $this->windowSeconds);

        return true;
    }
}
