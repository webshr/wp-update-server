<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Config;

use Webshr\WpUpdateServer\Support\Path;

final class ConfigValidator
{
    private const DEFAULT_GITHUB_TOKEN_ENV = 'GITHUB_TOKEN';

    public function validate(Config $config): ConfigValidationResult
    {
        $errors = [];
        $warnings = [];

        $this->validateServer($config->server, $errors, $warnings);
        $this->validatePackages($config->packages, $errors, $warnings);
        $this->validateLicenses($config->licenses, $errors, $warnings);

        return new ConfigValidationResult($errors, $warnings);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateServer(ServerConfig $server, array &$errors, array &$warnings): void
    {
        if ($server->signDownloads && ($server->downloadSecret === null || $server->downloadSecret === '')) {
            $errors[] = 'Download signing is enabled but WP_UPDATE_SERVER_SECRET/downloadSecret is empty.';
        }

        foreach (
            [
            'storageDir' => $server->storageDir,
            'cacheDir' => $server->cacheDir,
            'packageDir' => $server->packageDir,
            'logDir' => $server->logDir,
            ] as $name => $path
        ) {
            $this->validateWritablePath($name, $path, $errors, $warnings);
        }

        foreach (
            [
            'storageDir' => $server->storageDir,
            'cacheDir' => $server->cacheDir,
            'packageDir' => $server->packageDir,
            'logDir' => $server->logDir,
            ] as $name => $path
        ) {
            if ($this->insidePublicDirectory($server->rootDir, $path)) {
                $errors[] = sprintf('%s must not be inside the public web root: %s', $name, $path);
            }
        }

        foreach ($server->trustedProxies as $trustedProxy) {
            if (!$this->validIpOrCidr($trustedProxy)) {
                $errors[] = sprintf('trustedProxies contains an invalid IP/CIDR value: %s', $trustedProxy);
            }
        }

        foreach ($server->trustedProxyHeaders as $header) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', $header)) {
                $errors[] = sprintf('trustedProxyHeaders contains an invalid header name: %s', $header);
            }
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validatePackages(PackageRegistry $packages, array &$errors, array &$warnings): void
    {
        if ($packages->all() === []) {
            $warnings[] = 'No packages are configured.';
            return;
        }

        foreach ($packages->all() as $slug => $package) {
            if (!in_array($package->type, ['plugin', 'theme'], true)) {
                $errors[] = sprintf('Package "%s" has unsupported type "%s". Use "plugin" or "theme".', $slug, $package->type);
            }

            $kind = (string) ($package->source['kind'] ?? 'filesystem');
            match ($kind) {
                'filesystem' => $this->validateFilesystemPackage($package, $errors, $warnings),
                'github-release' => $this->validateGitHubPackage($package, $errors, $warnings),
                default => $errors[] = sprintf('Package "%s" has unsupported source kind "%s".', $slug, $kind),
            };

            foreach ($package->channels as $channelName => $channel) {
                if (!is_array($channel)) {
                    $errors[] = sprintf('Package "%s" channel "%s" must be an object.', $slug, (string) $channelName);
                    continue;
                }

                $pattern = $channel['versionPattern'] ?? null;
                if ($pattern !== null && !$this->validRegex((string) $pattern)) {
                    $errors[] = sprintf('Package "%s" channel "%s" has an invalid versionPattern.', $slug, (string) $channelName);
                }
            }
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateFilesystemPackage(PackageDefinition $package, array &$errors, array &$warnings): void
    {
        $source = $package->source;
        if (isset($source['versionPattern']) && !$this->validRegex((string) $source['versionPattern'])) {
            $errors[] = sprintf('Package "%s" has an invalid filesystem versionPattern.', $package->slug);
        }

        if ($package->versions === [] && !isset($source['path'])) {
            $warnings[] = sprintf('Package "%s" uses the default filesystem path. Ensure the archive exists before release.', $package->slug);
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateGitHubPackage(PackageDefinition $package, array &$errors, array &$warnings): void
    {
        $source = $package->source;
        $repo = (string) ($source['repo'] ?? '');
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo) !== 1) {
            $errors[] = sprintf('Package "%s" GitHub source requires source.repo as "owner/repo".', $package->slug);
        }

        if (!isset($source['asset']) && !isset($source['assetPattern'])) {
            $errors[] = sprintf('Package "%s" GitHub source requires source.asset or source.assetPattern.', $package->slug);
        }

        foreach (['versionPattern', 'assetPattern'] as $patternKey) {
            if (isset($source[$patternKey]) && $patternKey === 'versionPattern' && !$this->validRegex((string) $source[$patternKey])) {
                $errors[] = sprintf('Package "%s" GitHub source has an invalid %s.', $package->slug, $patternKey);
            }
        }

        $tokenEnv = (string) ($source['tokenEnv'] ?? self::DEFAULT_GITHUB_TOKEN_ENV);
        if (getenv($tokenEnv) === false || getenv($tokenEnv) === '') {
            $warnings[] = sprintf('Package "%s" references tokenEnv "%s", but it is not set.', $package->slug, $tokenEnv);
        }
    }

    /**
     * @param array<string, mixed> $licenses
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateLicenses(array $licenses, array &$errors, array &$warnings): void
    {
        foreach ($licenses as $id => $license) {
            if (!is_array($license)) {
                $errors[] = sprintf('License "%s" must be an object.', (string) $id);
                continue;
            }

            if (!isset($license['keyHash']) && !isset($license['key'])) {
                $warnings[] = sprintf('License "%s" has no keyHash/key.', (string) $id);
            }

            if (isset($license['packages']) && !is_array($license['packages'])) {
                $errors[] = sprintf('License "%s" packages must be a list.', (string) $id);
            }
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateWritablePath(string $name, string $path, array &$errors, array &$warnings): void
    {
        if (is_dir($path)) {
            if (!is_writable($path)) {
                $errors[] = sprintf('%s is not writable: %s', $name, $path);
            }
            return;
        }

        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent)) {
            $errors[] = sprintf('%s cannot be created because its parent is not writable: %s', $name, $path);
            return;
        }

        $warnings[] = sprintf('%s does not exist yet and will be created at runtime: %s', $name, $path);
    }

    private function insidePublicDirectory(string $rootDir, string $path): bool
    {
        $public = Path::normalize(rtrim($rootDir, "/\\") . DIRECTORY_SEPARATOR . 'public') . '/';
        $normalizedPath = Path::normalize(rtrim($path, "/\\")) . '/';

        return str_starts_with($normalizedPath, $public);
    }

    private function validRegex(string $pattern): bool
    {
        return @preg_match($pattern, '') !== false;
    }

    private function validIpOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (!str_contains($value, '/')) {
            return false;
        }

        [$ip, $prefix] = explode('/', $value, 2);
        $packed = inet_pton($ip);
        if ($packed === false || (string) (int) $prefix !== $prefix) {
            return false;
        }

        $prefixLength = (int) $prefix;
        return $prefixLength >= 0 && $prefixLength <= strlen($packed) * 8;
    }
}
