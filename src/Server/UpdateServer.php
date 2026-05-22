<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Server;

use Throwable;
use Webshr\WpUpdateServer\Auth\AuthorizationProviderInterface;
use Webshr\WpUpdateServer\Cache\CacheInterface;
use Webshr\WpUpdateServer\Config\Config;
use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Http\Request;
use Webshr\WpUpdateServer\Http\Response;
use Webshr\WpUpdateServer\License\LicenseManager;
use Webshr\WpUpdateServer\Logging\LoggerInterface;
use Webshr\WpUpdateServer\Package\PackageValidator;
use Webshr\WpUpdateServer\Package\VersionSelector;
use Webshr\WpUpdateServer\RateLimit\RateLimiterInterface;
use Webshr\WpUpdateServer\Security\DownloadSigner;
use Webshr\WpUpdateServer\Source\PackageSourceResolver;

final class UpdateServer
{
    public function __construct(
        private readonly Config $config,
        private readonly PackageSourceResolver $sources,
        private readonly PackageValidator $validator,
        private readonly CacheInterface $cache,
        private readonly DownloadSigner $signer,
        private readonly AuthorizationProviderInterface $authorization,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly LoggerInterface $logger,
        private readonly LicenseManager $licenses,
        private readonly VersionSelector $versionSelector = new VersionSelector()
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            return (new ActionRouter($this))->route($request);
        } catch (Throwable $exception) {
            $this->log('error', $request, ['message' => $exception->getMessage()]);

            return Response::json(['error' => $exception->getMessage()], 500);
        }
    }

    public function metadata(Request $request, string $slug): Response
    {
        $package = $this->packageFromSlug($slug);
        if (!$this->rateLimiter->allow('metadata-' . $package->slug, $request)) {
            $this->log('metadata_rate_limited', $request, ['slug' => $package->slug]);

            return Response::json(['error' => 'Too many metadata requests.'], 429);
        }

        if (!$this->authorization->canViewMetadata($package, $request)) {
            $this->log('metadata_denied', $request, ['slug' => $package->slug]);

            return Response::json(['error' => 'Unauthorized.'], 403);
        }

        $availableVersions = $this->sources->listVersions($package);
        $selectedVersion = $this->versionSelector->select(
            $package,
            $availableVersions,
            $request->queryString('installed_version') ?: null,
            $request->queryString('channel', 'stable'),
            $this->wordpressVersion($request),
            $this->phpVersion($request)
        );
        if ($selectedVersion === null) {
            $latestVersion = $this->versionSelector->select(
                $package,
                $availableVersions,
                null,
                $request->queryString('channel', 'stable'),
                $this->wordpressVersion($request),
                $this->phpVersion($request)
            );

            return $this->noUpdateResponse($package, $request, $latestVersion?->version);
        }

        $resolved = $this->sources->resolveVersion($package, $selectedVersion->version);
        $validation = $this->validator->validate($resolved->archivePath, $resolved->slug, $resolved->type);
        if (!$validation->valid) {
            $this->log('metadata_invalid_package', $request, ['slug' => $package->slug, 'errors' => $validation->errors]);

            return Response::json(['error' => 'Package validation failed.', 'details' => $validation->errors], 422);
        }

        $metadata = $resolved->metadata->toArray();
        $metadata['update_available'] = true;
        if ($this->authorization->canDownload($package, $request)) {
            $metadata['download_url'] = $this->downloadUrl($package, $resolved->version, $request);
        } elseif ($this->licenses->packageRequiresLicense($package)) {
            $metadata['license_required'] = true;
        } else {
            unset($metadata['download_url']);
        }

        $metadata['banners'] = $this->assets($package->slug, 'banners', ['low' => '-772x250', 'high' => '-1544x500']);
        $metadata['icons'] = $this->assets($package->slug, 'icons', ['1x' => '-128x128', '2x' => '-256x256', 'svg' => '']);
        $metadata = array_filter($metadata, static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        $this->log('metadata', $request, ['slug' => $package->slug, 'version' => $metadata['version'] ?? null]);

        return Response::json($metadata);
    }

    public function download(Request $request, string $slug, string $version): Response
    {
        $package = $this->packageFromSlug($slug);
        $version = preg_replace('/[^A-Za-z0-9._+\-]/', '', $version);
        if (!$this->signer->validate($request->query, ['slug' => $package->slug, 'version' => $version])) {
            $this->log('download_bad_signature', $request, ['slug' => $package->slug, 'version' => $version]);

            return Response::json(['error' => 'Invalid or expired download signature.'], 403);
        }

        $authorizedRequest = $this->requestWithDownloadToken($request, $package, $version);
        if ($authorizedRequest === null) {
            $this->log('download_bad_token', $request, ['slug' => $package->slug, 'version' => $version]);

            return Response::json(['error' => 'Invalid or expired download token.'], 403);
        }
        $request = $authorizedRequest;

        if (!$this->authorization->canDownload($package, $request)) {
            $this->log('download_denied', $request, ['slug' => $package->slug]);

            return Response::json(['error' => 'Unauthorized.'], 403);
        }

        if (!$this->rateLimiter->allow($package->slug, $request)) {
            $this->log('download_rate_limited', $request, ['slug' => $package->slug]);

            return Response::json(['error' => 'Too many download requests.'], 429);
        }

        $resolved = $this->sources->resolveVersion($package, $version);
        $validation = $this->validator->validate($resolved->archivePath, $resolved->slug, $resolved->type);
        if (!$validation->valid) {
            $this->log('download_invalid_package', $request, ['slug' => $package->slug, 'errors' => $validation->errors]);

            return Response::json(['error' => 'Package validation failed.', 'details' => $validation->errors], 422);
        }

        $this->log('download', $request, ['slug' => $package->slug, 'version' => $resolved->version, 'bytes' => filesize($resolved->archivePath)]);

        return Response::file($resolved->archivePath, $package->slug . '.zip');
    }

    public function clearCache(Request $request): Response
    {
        if (!$this->signer->enabled() || !$this->signer->validate($request->query)) {
            return Response::json(['error' => 'Invalid cache invalidation signature.'], 403);
        }
        if (!$this->rateLimiter->allow('cache-clear', $request)) {
            return Response::json(['error' => 'Too many cache clear requests.'], 429);
        }

        $namespace = $request->queryString('namespace');
        if ($namespace !== '' && !in_array($namespace, $this->allowedCacheNamespaces(), true)) {
            return Response::json(['error' => 'Invalid cache namespace.'], 400);
        }

        $this->cache->clear($namespace !== '' ? $namespace : null);
        $this->log('cache_cleared', $request, ['namespace' => $namespace ?: null]);

        return Response::json(['ok' => true]);
    }

    public function activateLicense(Request $request, string $slug): Response
    {
        $package = $this->packageFromSlug($slug);
        if (!$this->rateLimiter->allow('license-activate-' . $package->slug, $request)) {
            $this->log('license_activate_rate_limited', $request, ['slug' => $package->slug]);

            return Response::json(['error' => 'Too many license activation attempts.'], 429);
        }

        $result = $this->licenses->activate(
            $package->slug,
            $request->inputString('license_key'),
            $request->inputString('site_url') ?: (string) $request->siteUrl()
        );

        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);

        return Response::json($result, $status);
    }

    public function deactivateLicense(Request $request, string $slug): Response
    {
        $package = $this->packageFromSlug($slug);
        if (!$this->rateLimiter->allow('license-deactivate-' . $package->slug, $request)) {
            $this->log('license_deactivate_rate_limited', $request, ['slug' => $package->slug]);

            return Response::json(['error' => 'Too many license deactivation attempts.'], 429);
        }

        $result = $this->licenses->deactivate(
            $request->inputString('license_key'),
            $request->inputString('activation_id')
        );

        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);

        return Response::json($result, $status);
    }

    public function checkLicense(Request $request, string $slug): Response
    {
        $package = $this->packageFromSlug($slug);
        if (!$this->rateLimiter->allow('license-check-' . $package->slug, $request)) {
            $this->log('license_check_rate_limited', $request, ['slug' => $package->slug]);

            return Response::json(['error' => 'Too many license check requests.'], 429);
        }

        $result = $this->licenses->check(
            $package->slug,
            $request->inputString('license_key'),
            $request->inputString('activation_id') ?: null,
            $request->inputString('site_url') ?: $request->siteUrl()
        );

        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);

        return Response::json($result, $status);
    }

    private function packageFromSlug(string $slug): PackageDefinition
    {
        $slug = preg_replace('/[^A-Za-z0-9._+\-]/', '', $slug);
        if ($slug === '') {
            throw new \InvalidArgumentException('Missing package slug.');
        }
        if (!$this->config->packages->has($slug)) {
            if ($this->config->packages->all() === []) {
                throw new \InvalidArgumentException(sprintf('Unknown package "%s"; no packages are configured for this server process.', $slug));
            }

            throw new \InvalidArgumentException(sprintf('Unknown package "%s".', $slug));
        }

        return $this->config->packages->get($slug);
    }

    private function downloadUrl(PackageDefinition $package, string $version, Request $request): string
    {
        $params = $this->signer->sign([
            'slug' => $package->slug,
            'version' => $version,
            'token' => $this->downloadToken($package, $version, $request),
        ]);

        return $this->config->server->baseUrl . 'download/' . rawurlencode($package->slug) . '/' . rawurlencode($version) . '?' . http_build_query($params, '', '&');
    }

    private function noUpdateResponse(PackageDefinition $package, Request $request, ?string $latestVersion): Response
    {
        $payload = [
            'slug' => $package->slug,
            'update_available' => false,
            'version' => $latestVersion,
            'installed_version' => $request->queryString('installed_version') ?: null,
            'channel' => $request->queryString('channel', 'stable'),
        ];

        $this->log('metadata_no_update', $request, [
            'slug' => $package->slug,
            'version' => $latestVersion,
        ]);

        return Response::json(array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function wordpressVersion(Request $request): ?string
    {
        return $request->queryString('wp_version') ?: $request->wpVersion();
    }

    private function phpVersion(Request $request): ?string
    {
        return $request->queryString('php_version') ?: null;
    }

    /**
     * @param array<string, string> $map
     * @return array<string, string>|null
     */
    private function assets(string $slug, string $type, array $map): ?array
    {
        $base = $this->config->server->packageAssetDir . DIRECTORY_SEPARATOR . $type;
        if (!is_dir($base)) {
            return null;
        }

        $assets = [];
        foreach ($map as $key => $suffix) {
            $extensions = $key === 'svg' ? ['svg'] : ['png', 'jpg', 'jpeg'];
            foreach ($extensions as $extension) {
                $file = $base . DIRECTORY_SEPARATOR . $slug . $suffix . '.' . $extension;
                if (is_file($file)) {
                    $assets[$key] = $this->config->server->baseUrl . 'package-assets/' . $type . '/' . basename($file);
                    break;
                }
            }
        }

        return $assets === [] ? null : $assets;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $event, Request $request, array $context = []): void
    {
        $this->logger->log($event, $this->sanitizeLogContext(array_replace([
            'action' => $request->action(),
            'slug' => $context['slug'] ?? $request->slug(),
            'ip' => $request->clientIp,
            'method' => $request->method,
            'user_agent' => substr($request->header('User-Agent'), 0, 255),
            'installed_version' => $request->queryString('installed_version', '-'),
            'wp_version' => $request->wpVersion(),
            'site_url' => $this->loggableSiteUrl($request->siteUrl()),
        ], $context)));
    }

    private function downloadToken(PackageDefinition $package, string $version, Request $request): string
    {
        $token = bin2hex(random_bytes(32));
        $this->cache->set('download-tokens', $token, [
            'slug' => $package->slug,
            'version' => $version,
            'license_key' => $request->queryString('license_key'),
            'activation_id' => $request->queryString('activation_id'),
            'site_url' => $request->queryString('site_url') ?: (string) $request->siteUrl(),
        ], $this->config->server->downloadSignatureTtl);

        return $token;
    }

    private function requestWithDownloadToken(Request $request, PackageDefinition $package, string $version): ?Request
    {
        $token = $request->queryString('token');
        if ($token === '') {
            return $request;
        }

        $tokenData = $this->cache->get('download-tokens', $token);
        if (!is_array($tokenData) || ($tokenData['slug'] ?? null) !== $package->slug || ($tokenData['version'] ?? null) !== $version) {
            return null;
        }

        $query = $request->query;
        unset($query['token']);
        foreach (['license_key', 'activation_id', 'site_url'] as $key) {
            if (isset($tokenData[$key]) && is_scalar($tokenData[$key])) {
                $query[$key] = (string) $tokenData[$key];
            }
        }

        return new Request($query, $request->headers, $request->method, $request->clientIp, $request->uri, $request->body);
    }

    /**
     * @return list<string>
     */
    private function allowedCacheNamespaces(): array
    {
        return [
            'download-tokens',
            'github-assets',
            'github-release-list',
            'github-release-metadata',
            'license-activations',
            'normalized-packages',
            'rate-limit',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeLogContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array($key, ['license_key', 'activation_id', 'signature', 'token'], true)) {
                $context[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $context[$key] = $this->sanitizeLogContext($value);
            }
        }

        return $context;
    }

    private function loggableSiteUrl(?string $siteUrl): ?string
    {
        if ($siteUrl === null || $siteUrl === '') {
            return null;
        }

        $parts = parse_url($siteUrl);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
