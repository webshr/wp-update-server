<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Package;

final class PackageVersion
{
    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $release
     */
    public function __construct(
        public readonly string $version,
        public readonly string $sourceVersion,
        public readonly array $source,
        public readonly bool $prerelease = false,
        public readonly bool $draft = false,
        public readonly ?string $releaseDate = null,
        public readonly array $metadata = [],
        public readonly array $release = []
    ) {
    }

    public static function normalize(string $version): string
    {
        $version = trim($version);
        if (preg_match('/^v(?=\d)/i', $version) === 1) {
            $version = substr($version, 1);
        }

        return $version;
    }

    public static function isPrereleaseVersion(string $version): bool
    {
        return preg_match('/-(alpha|beta|rc)(?:\.?\d+)?$/i', $version) === 1;
    }
}
