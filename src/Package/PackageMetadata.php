<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Package;

final class PackageMetadata
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter($this->values, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self(array_replace_recursive($this->values, $overrides));
    }
}
