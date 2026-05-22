<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Logging;

interface LoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(string $event, array $context = []): void;
}
