<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Server;

use Webshr\WpUpdateServer\Auth\LicenseAuthorizationProvider;
use Webshr\WpUpdateServer\Cache\FilesystemCache;
use Webshr\WpUpdateServer\Config\Config;
use Webshr\WpUpdateServer\Config\ConfigLoader;
use Webshr\WpUpdateServer\Logging\JsonFileLogger;
use Webshr\WpUpdateServer\License\LicenseManager;
use Webshr\WpUpdateServer\Package\PackageInspector;
use Webshr\WpUpdateServer\Package\PackageValidator;
use Webshr\WpUpdateServer\Package\VersionSelector;
use Webshr\WpUpdateServer\RateLimit\IpDownloadRateLimiter;
use Webshr\WpUpdateServer\Security\DownloadSigner;
use Webshr\WpUpdateServer\Source\FilesystemPackageSource;
use Webshr\WpUpdateServer\Source\GitHubReleasePackageSource;
use Webshr\WpUpdateServer\Source\PackageSourceResolver;

final class ServerFactory
{
    public function __construct(private readonly string $rootDir)
    {
    }

    public function config(?string $configPath = null): Config
    {
        return ( new ConfigLoader($this->rootDir) )->load($configPath);
    }

    public function server(?string $configPath = null): UpdateServer
    {
        $config = $this->config($configPath);
        // Validate signing configuration: if signing is enabled we must have a non-empty secret
        if ($config->server->signDownloads && ( $config->server->downloadSecret === null || $config->server->downloadSecret === '' )) {
            throw new \RuntimeException('Download signing is enabled but no download secret is configured. Set WP_UPDATE_SERVER_SECRET or disable signing in config/server.php.');
        }
        $cache          = new FilesystemCache($config->server->cacheDir);
        $licenseManager = new LicenseManager($config->licenses, $cache);
        $inspector      = new PackageInspector();
        $validator      = new PackageValidator($inspector);
        $sources        = new PackageSourceResolver(
            new FilesystemPackageSource($config->server, $inspector),
            new GitHubReleasePackageSource($config->server, $cache, $inspector),
        );

        return new UpdateServer(
            $config,
            $sources,
            $validator,
            $cache,
            new DownloadSigner(
                $config->server->signDownloads ? $config->server->downloadSecret : null,
                $config->server->downloadSignatureTtl,
            ),
            new LicenseAuthorizationProvider($licenseManager),
            new IpDownloadRateLimiter($cache, $config->server->downloadLimit, $config->server->downloadWindowSeconds),
            new JsonFileLogger($config->server->logDir, $config->server->logMaxBytes),
            $licenseManager,
            new VersionSelector(),
        );
    }
}
