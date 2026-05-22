<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Config;

/**
 * @phpstan-type PackageSource array{
 *     kind?: string,
 *     path?: string,
 *     repo?: string,
 *     asset?: string,
 *     assetPattern?: string,
 *     release?: string,
 *     releaseStrategy?: string,
 *     versionFrom?: string,
 *     versionPattern?: string,
 *     tokenEnv?: string,
 *     cacheTtl?: int,
 *     maxPages?: int,
 *     includeDrafts?: bool
 * }
 * @phpstan-type PackageLicense array{required?: bool}
 */
final class PackageDefinition
{
    /**
     * @param PackageSource $source
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $versions
     * @param array<string, mixed> $channels
     * @param PackageLicense $license
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $type,
        public readonly array $source,
        public readonly array $metadata = [],
        public readonly array $versions = [],
        public readonly array $channels = [],
        public readonly array $license = [],
        public readonly bool $normalizeZip = false,
        public readonly array $raw = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $slug, array $data): self
    {
        $source = $data['source'] ?? ['kind' => 'filesystem'];
        if (!is_array($source)) {
            $source = ['kind' => 'filesystem', 'path' => (string) $source];
        }

        $metadata = $data['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $versions = $data['versions'] ?? [];
        if (!is_array($versions)) {
            $versions = [];
        }

        $channels = $data['channels'] ?? [];
        if (!is_array($channels)) {
            $channels = [];
        }

        $license = $data['license'] ?? [];
        if (!is_array($license)) {
            $license = [];
        }

        return new self(
            $slug,
            (string) ($data['type'] ?? 'plugin'),
            $source,
            $metadata,
            $versions,
            $channels,
            $license,
            (bool) ($data['normalizeZip'] ?? false),
            $data
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function channel(string $name): array
    {
        $default = [
            'versionPattern' => '/^\d+(?:\.\d+){0,2}$/',
            'includePrereleases' => false,
        ];

        if ($name === 'stable') {
            return array_replace($default, is_array($this->channels['stable'] ?? null) ? $this->channels['stable'] : []);
        }

        $fallback = [
            'versionPattern' => '/^\d+\.\d+\.\d+-(alpha|beta|rc)(?:\.\d+)?$/',
            'includePrereleases' => true,
        ];

        return array_replace($fallback, is_array($this->channels[$name] ?? null) ? $this->channels[$name] : []);
    }
}
