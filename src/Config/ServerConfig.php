<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Config;

use Webshr\WpUpdateServer\Support\Path;

final class ServerConfig
{
    /**
     * @param array<string, mixed> $raw
     * @param list<string> $trustedProxies
     * @param list<string> $trustedProxyHeaders
     */
    public function __construct(
        public readonly string $rootDir,
        public readonly string $baseUrl,
        public readonly string $storageDir,
        public readonly string $cacheDir,
        public readonly string $packageDir,
        public readonly string $packageAssetDir,
        public readonly string $logDir,
        public readonly bool $signDownloads,
        public readonly ?string $downloadSecret,
        public readonly int $defaultCacheTtl,
        public readonly int $downloadSignatureTtl,
        public readonly int $downloadLimit,
        public readonly int $downloadWindowSeconds,
        public readonly array $trustedProxies = [],
        public readonly array $trustedProxyHeaders = ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP'],
        public readonly int $logMaxBytes = 10485760,
        public readonly array $raw = []
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, string $rootDir): self
    {
        $server = $config['server'] ?? [];
        if (!is_array($server)) {
            $server = [];
        }

        $storageDir = Path::resolve((string) ($server['storageDir'] ?? 'storage'), $rootDir);

        return new self(
            $rootDir,
            rtrim((string) ($server['baseUrl'] ?? self::guessBaseUrl()), '/') . '/',
            $storageDir,
            Path::resolve((string) ($server['cacheDir'] ?? rtrim($storageDir, "/\\") . DIRECTORY_SEPARATOR . 'cache'), $rootDir),
            Path::resolve((string) ($server['packageDir'] ?? rtrim($storageDir, "/\\") . DIRECTORY_SEPARATOR . 'packages'), $rootDir),
            Path::resolve((string) ($server['packageAssetDir'] ?? 'public/package-assets'), $rootDir),
            Path::resolve((string) ($server['logDir'] ?? rtrim($storageDir, "/\\") . DIRECTORY_SEPARATOR . 'logs'), $rootDir),
            (bool) ($server['signDownloads'] ?? false),
            isset($server['downloadSecret']) ? (string) $server['downloadSecret'] : null,
            max(0, (int) ($server['defaultCacheTtl'] ?? 3600)),
            max(60, (int) ($server['downloadSignatureTtl'] ?? 900)),
            max(1, (int) ($server['downloadLimit'] ?? 60)),
            max(60, (int) ($server['downloadWindowSeconds'] ?? 3600)),
            self::stringList($server['trustedProxies'] ?? []),
            self::stringList($server['trustedProxyHeaders'] ?? ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP']),
            max(1024, (int) ($server['logMaxBytes'] ?? 10485760)),
            $server
        );
    }

    public static function guessBaseUrl(): string
    {
        if (!isset($_SERVER['HTTP_HOST'])) {
            return '/';
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $path = str_replace('\\', '/', dirname($script));
        $path = $path === '/' ? '/' : rtrim($path, '/') . '/';

        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $path;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
