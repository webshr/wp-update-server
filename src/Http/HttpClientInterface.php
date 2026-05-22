<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Http;

interface HttpClientInterface
{
    /**
     * @param list<string> $headers
     */
    public function get(string $url, array $headers = []): string;
}
