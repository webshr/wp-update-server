<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Source;

use RuntimeException;
use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Config\ServerConfig;
use Webshr\WpUpdateServer\Package\PackageInspector;
use Webshr\WpUpdateServer\Package\PackageMetadata;
use Webshr\WpUpdateServer\Package\PackageVersion;
use Webshr\WpUpdateServer\Package\PackageVersionCollection;
use Webshr\WpUpdateServer\Package\ResolvedPackage;
use Webshr\WpUpdateServer\Support\Arr;
use Webshr\WpUpdateServer\Support\Path;

final class FilesystemPackageSource implements PackageSourceInterface
{
    public function __construct(
        private readonly ServerConfig $serverConfig,
        private readonly PackageInspector $inspector
    ) {
    }

    public function supports(PackageDefinition $package): bool
    {
        return ($package->source['kind'] ?? 'filesystem') === 'filesystem';
    }

    public function listVersions(PackageDefinition $package): PackageVersionCollection
    {
        $versions = new PackageVersionCollection();

        if ($package->versions !== []) {
            foreach ($package->versions as $version => $definition) {
                if (!is_array($definition)) {
                    continue;
                }
                $source = $definition['source'] ?? $package->source;
                $metadata = $definition['metadata'] ?? [];
                $source = is_array($source) ? $source : $package->source;
                $metadata = is_array($metadata) ? $metadata : [];
                $normalized = PackageVersion::normalize((string) $version);
                $archiveMetadata = $this->inspectArchive($this->pathFromSource($source, $package), $package);
                $metadata = Arr::mergeRecursive($archiveMetadata, $package->metadata);
                $metadata = Arr::mergeRecursive($metadata, is_array($definition['metadata'] ?? null) ? $definition['metadata'] : []);
                $versions->add(new PackageVersion(
                    $normalized,
                    (string) $version,
                    $source,
                    PackageVersion::isPrereleaseVersion($normalized),
                    false,
                    null,
                    $metadata
                ));
            }

            return $versions;
        }

        $path = isset($package->source['path'])
            ? Path::resolve((string) $package->source['path'], $this->serverConfig->rootDir)
            : $this->serverConfig->packageDir . DIRECTORY_SEPARATOR . $package->slug . '.zip';

        if (is_dir($path) && isset($package->source['versionPattern'])) {
            $pattern = (string) $package->source['versionPattern'];
            foreach (glob(rtrim($path, "/\\") . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $archivePath) {
                $fileName = basename($archivePath);
                if (preg_match($pattern, $fileName, $matches) !== 1 || !isset($matches['version'])) {
                    continue;
                }
                $normalized = PackageVersion::normalize((string) $matches['version']);
                $source = array_replace($package->source, ['path' => $archivePath]);
                $metadata = $this->inspectArchive($archivePath, $package);
                $metadata = Arr::mergeRecursive($metadata, $package->metadata);
                $versions->add(new PackageVersion(
                    $normalized,
                    (string) $matches['version'],
                    $source,
                    PackageVersion::isPrereleaseVersion($normalized),
                    false,
                    is_file($archivePath) ? gmdate('c', (int) filemtime($archivePath)) : null,
                    $metadata
                ));
            }

            return $versions;
        }

        $metadata = $this->inspectArchive($path, $package);
        $version = PackageVersion::normalize((string) ($metadata['version'] ?? '0.0.0'));
        $versions->add(new PackageVersion(
            $version,
            $version,
            $package->source,
            PackageVersion::isPrereleaseVersion($version),
            false,
            is_file($path) ? gmdate('c', (int) filemtime($path)) : null,
            Arr::mergeRecursive($metadata, $package->metadata)
        ));

        return $versions;
    }

    public function resolveVersion(PackageDefinition $package, string $version): ResolvedPackage
    {
        $packageVersion = $this->listVersions($package)->get($version);
        $path = $this->pathFromSource($packageVersion->source, $package);
        $metadata = $this->inspectArchive($path, $package);
        $metadata['version'] = $packageVersion->version;
        $metadata = Arr::mergeRecursive($metadata, $package->metadata);
        $metadata = Arr::mergeRecursive($metadata, $packageVersion->metadata);

        return new ResolvedPackage($package->slug, $package->type, $packageVersion->version, $path, new PackageMetadata($metadata));
    }

    /**
     * @param array<string, mixed> $source
     */
    private function pathFromSource(array $source, PackageDefinition $package): string
    {
        return isset($source['path'])
            ? Path::resolve((string) $source['path'], $this->serverConfig->rootDir)
            : $this->serverConfig->packageDir . DIRECTORY_SEPARATOR . $package->slug . '.zip';
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectArchive(string $path, PackageDefinition $package): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Package archive "%s" is missing or unreadable.', $path));
        }

        return $this->inspector->inspect($path, $package->slug, $package->type);
    }
}
