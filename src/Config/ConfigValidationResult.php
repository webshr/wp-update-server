<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Config;

final class ConfigValidationResult
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors = [], public readonly array $warnings = [])
    {
    }

    public function valid(): bool
    {
        return $this->errors === [];
    }
}
