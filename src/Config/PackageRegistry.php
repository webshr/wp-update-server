<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Config;

use InvalidArgumentException;

final class PackageRegistry
{
    /** @var array<string, PackageDefinition> */
    private array $packages = [];

    /**
     * @param array<string, mixed> $packages
     */
    public function __construct(array $packages)
    {
        foreach ($packages as $slug => $definition) {
            if (!is_array($definition)) {
                throw new InvalidArgumentException(sprintf('Package "%s" must be an object.', $slug));
            }
            $safeSlug = preg_replace('/[^A-Za-z0-9._+\-]/', '', (string) $slug);
            if ($safeSlug === '') {
                throw new InvalidArgumentException('Package slug cannot be empty.');
            }
            $this->packages[$safeSlug] = PackageDefinition::fromArray($safeSlug, $definition);
        }
    }

    public function has(string $slug): bool
    {
        return isset($this->packages[$slug]);
    }

    public function get(string $slug): PackageDefinition
    {
        if (!$this->has($slug)) {
            throw new InvalidArgumentException(sprintf('Unknown package "%s".', $slug));
        }

        return $this->packages[$slug];
    }

    /**
     * @return array<string, PackageDefinition>
     */
    public function all(): array
    {
        return $this->packages;
    }
}
