<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Package;

use Webshr\WpUpdateServer\Config\PackageDefinition;

final class VersionSelector
{
    public function select(
        PackageDefinition $package,
        PackageVersionCollection $versions,
        ?string $installedVersion,
        string $channel,
        ?string $wordpressVersion = null,
        ?string $phpVersion = null
    ): ?PackageVersion {
        $candidates = array_filter(
            $versions->all(),
            fn (PackageVersion $version): bool => $this->allowedForChannel($package, $version, $channel)
                && $this->compatibleWithEnvironment($version, $wordpressVersion, $phpVersion)
        );

        if ($installedVersion !== null && trim($installedVersion) !== '') {
            $installedVersion = PackageVersion::normalize($installedVersion);
            $candidates = array_filter(
                $candidates,
                static fn (PackageVersion $version): bool => version_compare($version->version, $installedVersion, '>')
            );
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn (PackageVersion $a, PackageVersion $b): int => version_compare($b->version, $a->version)
        );

        return $candidates[0];
    }

    private function allowedForChannel(PackageDefinition $package, PackageVersion $version, string $channel): bool
    {
        $channelConfig = $package->channel($channel);
        $includePrereleases = (bool) ($channelConfig['includePrereleases'] ?? ($channel !== 'stable'));
        if (!$includePrereleases && $version->prerelease) {
            return false;
        }

        $pattern = isset($channelConfig['versionPattern']) ? (string) $channelConfig['versionPattern'] : null;
        if ($pattern !== null && @preg_match($pattern, '') !== false) {
            return preg_match($pattern, $version->version) === 1;
        }

        if ($channel === 'stable') {
            return !$version->prerelease;
        }

        if (in_array($channel, ['alpha', 'beta', 'rc'], true)) {
            return !$version->prerelease || str_contains(strtolower($version->version), '-' . $channel);
        }

        return true;
    }

    private function compatibleWithEnvironment(PackageVersion $version, ?string $wordpressVersion, ?string $phpVersion): bool
    {
        $requiresWordPress = $this->metadataValue($version, 'requires');
        if ($wordpressVersion !== null && $requiresWordPress !== null && version_compare($wordpressVersion, $requiresWordPress, '<')) {
            return false;
        }

        $requiresPhp = $this->metadataValue($version, 'requires_php');
        if ($phpVersion !== null && $requiresPhp !== null && version_compare($phpVersion, $requiresPhp, '<')) {
            return false;
        }

        return true;
    }

    private function metadataValue(PackageVersion $version, string $key): ?string
    {
        $value = $version->metadata[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
