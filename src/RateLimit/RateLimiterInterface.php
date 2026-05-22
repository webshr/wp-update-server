<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\RateLimit;

use Webshr\WpUpdateServer\Http\Request;

interface RateLimiterInterface
{
    public function allow(string $slug, Request $request): bool;
}
