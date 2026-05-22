<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Package;

use RuntimeException;

final class PackageVersionCollection
{
    /** @var array<string, PackageVersion> */
    private array $versions = [];

    /**
     * @param iterable<PackageVersion> $versions
     */
    public function __construct(iterable $versions = [])
    {
        foreach ($versions as $version) {
            $this->versions[$version->version] = $version;
        }
    }

    public function add(PackageVersion $version): void
    {
        $this->versions[$version->version] = $version;
    }

    public function get(string $version): PackageVersion
    {
        $normalized = PackageVersion::normalize($version);
        if (!isset($this->versions[$normalized])) {
            throw new RuntimeException(sprintf('Version "%s" is not available.', $version));
        }

        return $this->versions[$normalized];
    }

    public function has(string $version): bool
    {
        return isset($this->versions[PackageVersion::normalize($version)]);
    }

    /**
     * @return list<PackageVersion>
     */
    public function all(): array
    {
        $versions = array_values($this->versions);
        usort(
            $versions,
            static fn (PackageVersion $a, PackageVersion $b): int => version_compare($b->version, $a->version)
        );

        return $versions;
    }

    public function count(): int
    {
        return count($this->versions);
    }
}
