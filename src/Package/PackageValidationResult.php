<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Package;

final class PackageValidationResult
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors = [],
        public readonly array $warnings = []
    ) {
    }

    public static function valid(array $warnings = []): self
    {
        return new self(true, [], $warnings);
    }

    public static function invalid(array $errors, array $warnings = []): self
    {
        return new self(false, $errors, $warnings);
    }
}
