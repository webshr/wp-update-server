<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Source;

use RuntimeException;
use ZipArchive;
use Webshr\WpUpdateServer\Cache\FilesystemCache;
use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Config\ServerConfig;
use Webshr\WpUpdateServer\Http\HttpClientInterface;
use Webshr\WpUpdateServer\Http\StreamHttpClient;
use Webshr\WpUpdateServer\Package\PackageInspector;
use Webshr\WpUpdateServer\Package\PackageArchiveNormalizer;
use Webshr\WpUpdateServer\Package\PackageMetadata;
use Webshr\WpUpdateServer\Package\PackageVersion;
use Webshr\WpUpdateServer\Package\PackageVersionCollection;
use Webshr\WpUpdateServer\Package\ResolvedPackage;
use Webshr\WpUpdateServer\Support\Arr;

final class GitHubReleasePackageSource implements PackageSourceInterface
{
    private const DEFAULT_TOKEN_ENV = 'GITHUB_TOKEN';

    public function __construct(
        private readonly ServerConfig $serverConfig,
        private readonly FilesystemCache $cache,
        private readonly PackageInspector $inspector,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly PackageArchiveNormalizer $normalizer = new PackageArchiveNormalizer()
    ) {
    }

    public function supports(PackageDefinition $package): bool
    {
        return ($package->source['kind'] ?? '') === 'github-release';
    }

    public function listVersions(PackageDefinition $package): PackageVersionCollection
    {
        $source = $package->source;
        $repo = (string) ($source['repo'] ?? '');
        if ($repo === '') {
            throw new RuntimeException(sprintf('GitHub package "%s" is missing source.repo.', $package->slug));
        }

        $versions = new PackageVersionCollection();
        foreach ($this->releases($package) as $release) {
            if (!$this->releaseAllowed($release, $source)) {
                continue;
            }
            try {
                $version = $this->versionFromRelease($release, $source);
            } catch (RuntimeException) {
                continue;
            }
            if (!$this->versionAllowed($version, $source)) {
                continue;
            }
            $asset = $this->selectAsset($release, $source, false);
            if ($asset === null) {
                continue;
            }

            $metadata = is_array($release['metadata'] ?? null) ? $release['metadata'] : [];
            $isPrerelease = ((bool) ($release['prerelease'] ?? false))
                || PackageVersion::isPrereleaseVersion($version);

            $versions->add(new PackageVersion(
                $version,
                (string) ($release[(string) ($source['versionFrom'] ?? 'tag_name')] ?? $version),
                ['kind' => 'github-release', 'release' => $release, 'asset' => $asset],
                $isPrerelease,
                (bool) ($release['draft'] ?? false),
                is_scalar($release['published_at'] ?? null) ? (string) $release['published_at'] : null,
                $metadata,
                $release
            ));
        }

        return $versions;
    }

    public function resolveVersion(PackageDefinition $package, string $version): ResolvedPackage
    {
        $packageVersion = $this->listVersions($package)->get($version);
        $asset = $packageVersion->source['asset'] ?? null;
        if (!is_array($asset)) {
            throw new RuntimeException(sprintf('GitHub version "%s" has no asset.', $packageVersion->version));
        }

        $archivePath = $this->downloadAsset($package, $packageVersion, $asset);
        $archivePath = $this->normalizeArchive($archivePath, $package, $packageVersion);
        $metadata = $this->inspector->inspect($archivePath, $package->slug, $package->type);
        $metadata['version'] = $packageVersion->version;
        $metadata = Arr::mergeRecursive($metadata, $package->metadata);
        $metadata = Arr::mergeRecursive($metadata, $packageVersion->metadata);

        return new ResolvedPackage($package->slug, $package->type, $packageVersion->version, $archivePath, new PackageMetadata($metadata));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function releases(PackageDefinition $package): array
    {
        if (($package->source['releaseStrategy'] ?? '') === 'versions') {
            return $this->releaseList($package);
        }

        return [$this->release($package)];
    }

    private function versionFromRelease(array $release, array $source): string
    {
        $versionFrom = (string) ($source['versionFrom'] ?? 'tag_name');
        if (isset($release[$versionFrom]) && is_scalar($release[$versionFrom])) {
            return PackageVersion::normalize((string) $release[$versionFrom]);
        }

        throw new RuntimeException(sprintf('GitHub release is missing version field "%s".', $versionFrom));
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $source
     */
    private function releaseAllowed(array $release, array $source): bool
    {
        if (!(bool) ($source['includeDrafts'] ?? false) && (bool) ($release['draft'] ?? false)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function versionAllowed(string $version, array $source): bool
    {
        $pattern = isset($source['versionPattern']) ? (string) $source['versionPattern'] : null;
        if ($pattern === null) {
            return true;
        }

        return @preg_match($pattern, '') !== false && preg_match($pattern, $version) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function release(PackageDefinition $package): array
    {
        $source = $package->source;
        $repo = (string) $source['repo'];
        $release = (string) ($source['release'] ?? 'latest');
        $ttl = (int) ($source['cacheTtl'] ?? $this->serverConfig->defaultCacheTtl);
        $cacheKey = $repo . '|' . $release;

        $cached = $this->cache->get('github-release-metadata', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $url = $release === 'latest'
            ? sprintf('https://api.github.com/repos/%s/releases/latest', $repo)
            : sprintf('https://api.github.com/repos/%s/releases/tags/%s', $repo, rawurlencode($release));

        $data = $this->requestGitHubReleaseJson($package, $url);
        $this->cache->set('github-release-metadata', $cacheKey, $data, $ttl);

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function releaseList(PackageDefinition $package): array
    {
        $source = $package->source;
        $repo = (string) $source['repo'];
        $ttl = (int) ($source['cacheTtl'] ?? $this->serverConfig->defaultCacheTtl);
        $cacheKey = $repo . '|versions|' . json_encode([
            'versionFrom' => $source['versionFrom'] ?? 'tag_name',
            'versionPattern' => $source['versionPattern'] ?? null,
        ]);

        $cached = $this->cache->get('github-release-list', $cacheKey);
        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_array'));
        }

        $releases = [];
        $maxPages = max(1, (int) ($source['maxPages'] ?? 10));
        for ($page = 1; $page <= $maxPages; $page++) {
            $url = sprintf('https://api.github.com/repos/%s/releases?per_page=100&page=%d', $repo, $page);
            $pageData = $this->requestGitHubReleaseJson($package, $url);
            if (!array_is_list($pageData)) {
                $message = isset($pageData['message']) && is_scalar($pageData['message'])
                    ? (string) $pageData['message']
                    : 'unexpected response shape';

                throw new RuntimeException(sprintf('GitHub release list request failed for "%s": %s.', $repo, $message));
            }
            if ($pageData === []) {
                break;
            }
            foreach ($pageData as $release) {
                if (is_array($release)) {
                    $releases[] = $release;
                }
            }
            if (count($pageData) < 100) {
                break;
            }
        }

        $this->cache->set('github-release-list', $cacheKey, $releases, $ttl);

        return $releases;
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function selectAsset(array $release, array $source, bool $throwWhenMissing = true): ?array
    {
        $assets = $release['assets'] ?? [];
        if (!is_array($assets)) {
            throw new RuntimeException('GitHub release has no assets.');
        }

        $exact = isset($source['asset']) ? (string) $source['asset'] : null;
        $pattern = isset($source['assetPattern']) ? (string) $source['assetPattern'] : null;

        foreach ($assets as $asset) {
            if (!is_array($asset) || !isset($asset['name'])) {
                continue;
            }
            $name = (string) $asset['name'];
            if (($exact !== null && $name === $exact) || ($pattern !== null && fnmatch($pattern, $name))) {
                return $asset;
            }
        }

        if ($throwWhenMissing) {
            throw new RuntimeException('No GitHub release asset matched the configured asset selector.');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function downloadAsset(PackageDefinition $package, PackageVersion $version, array $asset): string
    {
        $downloadUrl = (string) ($asset['url'] ?? $asset['browser_download_url'] ?? '');
        if ($downloadUrl === '') {
            throw new RuntimeException('Selected GitHub asset is missing a download URL.');
        }

        $assetName = (string) ($asset['name'] ?? $package->slug . '.zip');
        $key = $package->slug . '|' . $version->version . '|' . $assetName . '|' . (string) ($asset['updated_at'] ?? $asset['id'] ?? '');
        $path = $this->cache->artifactPath('github-assets', $key, 'zip');
        if (is_file($path) && filesize($path) > 0 && $this->isZipArchive($path)) {
            return $path;
        }
        if (is_file($path)) {
            unlink($path);
        }

        $contents = $this->requestBinary($downloadUrl, $this->token($package->source), ['Accept: application/octet-stream']);
        $this->writeZipArchive($path, $contents, $assetName);

        return $path;
    }

    private function writeZipArchive(string $path, string $contents, string $assetName): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            $written = file_put_contents($tmp, $contents, LOCK_EX);
            if ($written === false || $written !== strlen($contents)) {
                throw new RuntimeException(sprintf('Unable to write GitHub asset "%s" to cache.', $assetName));
            }

            @chmod($tmp, 0600);

            if (!$this->isZipArchive($tmp)) {
                throw new RuntimeException(sprintf('GitHub asset "%s" did not return a valid ZIP archive.', $assetName));
            }

            if (!@rename($tmp, $path)) {
                throw new RuntimeException(sprintf('Unable to cache GitHub asset "%s".', $assetName));
            }

            @chmod($path, 0600);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function isZipArchive(string $path): bool
    {
        $zip = new ZipArchive();
        $opened = $zip->open($path) === true;
        if ($opened) {
            $zip->close();
        }

        return $opened;
    }

    private function normalizeArchive(string $archivePath, PackageDefinition $package, PackageVersion $version): string
    {
        if (!$package->normalizeZip) {
            return $archivePath;
        }

        $key = $package->slug . '|' . $version->version . '|' . $archivePath . '|' . filesize($archivePath) . '|' . filemtime($archivePath);
        $targetPath = $this->cache->artifactPath('normalized-packages', $key, 'zip');
        if (is_file($targetPath) && filesize($targetPath) > 0) {
            return $targetPath;
        }

        return $this->normalizer->normalizeTo($archivePath, $targetPath, $package->slug);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function token(array $source): ?string
    {
        $tokenEnv = $this->tokenEnv($source);
        $token = getenv($tokenEnv);

        return $token === false || $token === '' ? null : $token;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function tokenEnv(array $source): string
    {
        $tokenEnv = (string) ($source['tokenEnv'] ?? '');

        return $tokenEnv !== '' ? $tokenEnv : self::DEFAULT_TOKEN_ENV;
    }

    /**
     * @return array<mixed>
     */
    private function requestJson(string $url, ?string $token): array
    {
        $body = $this->requestBinary($url, $token, [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ]);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('GitHub returned invalid JSON for "%s".', $url));
        }
        if (isset($data['message'], $data['status']) && is_scalar($data['message'])) {
            throw new RuntimeException(sprintf('GitHub request failed for "%s": %s.', $url, (string) $data['message']));
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private function requestGitHubReleaseJson(PackageDefinition $package, string $url): array
    {
        try {
            return $this->requestJson($url, $this->token($package->source));
        } catch (RuntimeException $exception) {
            if (!$this->looksLikeGitHubNotFound($exception)) {
                throw $exception;
            }

            throw new RuntimeException($this->githubNotFoundMessage($package, $url, $exception), 0, $exception);
        }
    }

    private function looksLikeGitHubNotFound(RuntimeException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'HTTP 404')
            || str_contains($message, ': Not Found.')
            || str_ends_with($message, ': Not Found');
    }

    private function githubNotFoundMessage(PackageDefinition $package, string $url, RuntimeException $exception): string
    {
        $repo = (string) ($package->source['repo'] ?? '');
        $tokenEnv = $this->tokenEnv($package->source);
        $message = sprintf('GitHub could not find repository "%s" while fetching releases from "%s".', $repo, $url);

        $token = getenv($tokenEnv);
        if ($token === false || $token === '') {
            return $message . sprintf(' source.tokenEnv "%s" is not set for this process; private repositories need a token with read access.', $tokenEnv);
        }

        return $message . sprintf(
            ' source.tokenEnv "%s" is set, so verify that the token can read "%s" and that source.repo is correct. Original error: %s',
            $tokenEnv,
            $repo,
            $exception->getMessage()
        );
    }

    /**
     * @param list<string> $headers
     */
    private function requestBinary(string $url, ?string $token, array $headers = []): string
    {
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return ($this->httpClient ?? new StreamHttpClient())->get($url, $headers);
    }
}
