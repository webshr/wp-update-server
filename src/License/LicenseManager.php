<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\License;

use Webshr\WpUpdateServer\Cache\CacheInterface;
use Webshr\WpUpdateServer\Config\PackageDefinition;

final class LicenseManager
{
    private const ACTIVATION_TTL = 315360000;

    /**
     * @param array<string, mixed> $licenses
     */
    public function __construct(
        private readonly array $licenses,
        private readonly CacheInterface $cache
    ) {
    }

    public function packageRequiresLicense(PackageDefinition $package): bool
    {
        return (bool) ($package->license['required'] ?? false);
    }

    public function canAccess(PackageDefinition $package, ?string $licenseKey, ?string $activationId, ?string $siteUrl): bool
    {
        if (!$this->packageRequiresLicense($package)) {
            return true;
        }

        $license = $this->findLicense($licenseKey);
        if ($license === null || !$this->licenseAllowsPackage($license, $package->slug) || !$this->licenseIsActive($license)) {
            return false;
        }

        $activationId = trim((string) $activationId);
        $siteUrl = $this->normalizeSiteUrl((string) $siteUrl);
        if ($activationId === '' || $siteUrl === '') {
            return false;
        }

        foreach ($this->activations($license['id']) as $activation) {
            if (($activation['id'] ?? '') === $activationId && ($activation['site_url'] ?? '') === $siteUrl) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(string $slug, string $licenseKey, string $siteUrl): array
    {
        $license = $this->findLicense($licenseKey);
        $siteUrl = $this->normalizeSiteUrl($siteUrl);

        if ($license === null) {
            return ['success' => false, 'status' => 404, 'message' => 'Invalid license.'];
        }
        if (!$this->licenseAllowsPackage($license, $slug)) {
            return ['success' => false, 'status' => 403, 'message' => 'This license is not valid for this product.'];
        }
        if (!$this->licenseIsActive($license)) {
            return ['success' => false, 'status' => 403, 'message' => 'License is not active.', 'license_status' => $license['status']];
        }
        if ($siteUrl === '') {
            return ['success' => false, 'status' => 400, 'message' => 'Missing site_url.'];
        }

        $activations = $this->activations($license['id']);
        foreach ($activations as $activation) {
            if (($activation['site_url'] ?? '') === $siteUrl) {
                return $this->activationResponse($license, $activation, $activations);
            }
        }

        $limit = (int) ($license['activationLimit'] ?? 0);
        if ($limit > 0 && count($activations) >= $limit) {
            return [
                'success' => false,
                'status' => 403,
                'message' => 'Activation limit reached.',
                'used_activations' => count($activations),
                'remaining_activations' => 0,
            ];
        }

        $activation = [
            'id' => 'act_' . bin2hex(random_bytes(16)),
            'site_url' => $siteUrl,
            'created_at' => gmdate('c'),
        ];
        $activations[] = $activation;
        $this->saveActivations($license['id'], $activations);

        return $this->activationResponse($license, $activation, $activations);
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivate(string $licenseKey, string $activationId): array
    {
        $license = $this->findLicense($licenseKey);
        if ($license === null) {
            return ['success' => false, 'status' => 404, 'message' => 'Invalid license.'];
        }

        $activations = $this->activations($license['id']);
        $filtered = array_values(array_filter(
            $activations,
            static fn (array $activation): bool => ($activation['id'] ?? '') !== $activationId
        ));

        if (count($filtered) === count($activations)) {
            return ['success' => false, 'status' => 404, 'message' => 'Activation not found.'];
        }

        $this->saveActivations($license['id'], $filtered);

        return [
            'success' => true,
            'status' => 200,
            'message' => 'License deactivated successfully.',
            'used_activations' => count($filtered),
            'remaining_activations' => $this->remainingActivations($license, $filtered),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $slug, string $licenseKey, ?string $activationId, ?string $siteUrl): array
    {
        $license = $this->findLicense($licenseKey);
        if ($license === null) {
            return ['success' => false, 'status' => 404, 'message' => 'Invalid license.'];
        }

        $activations = $this->activations($license['id']);
        $siteUrl = $this->normalizeSiteUrl((string) $siteUrl);
        $siteActivated = false;
        foreach ($activations as $activation) {
            $matchesActivation = $activationId === null || $activationId === '' || ($activation['id'] ?? '') === $activationId;
            $matchesSite = $siteUrl === '' || ($activation['site_url'] ?? '') === $siteUrl;
            if ($matchesActivation && $matchesSite) {
                $siteActivated = true;
                break;
            }
        }

        return [
            'success' => true,
            'status' => 200,
            'license_status' => $license['status'],
            'valid_for_product' => $this->licenseAllowsPackage($license, $slug),
            'active' => $this->licenseIsActive($license),
            'site_activated' => $siteActivated,
            'expires_at' => $license['expiresAt'] ?? null,
            'used_activations' => count($activations),
            'remaining_activations' => $this->remainingActivations($license, $activations),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLicense(?string $licenseKey): ?array
    {
        $licenseKey = trim((string) $licenseKey);
        if ($licenseKey === '') {
            return null;
        }

        foreach ($this->licenses as $id => $license) {
            if (!is_array($license)) {
                continue;
            }

            $license['id'] = (string) $id;
            $key = isset($license['key']) && is_scalar($license['key']) ? (string) $license['key'] : null;
            $keyHash = isset($license['keyHash']) && is_scalar($license['keyHash']) ? (string) $license['keyHash'] : null;
            if (($key !== null && hash_equals($key, $licenseKey)) || ($keyHash !== null && hash_equals($keyHash, hash('sha256', $licenseKey)))) {
                return $license;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $license
     */
    private function licenseAllowsPackage(array $license, string $slug): bool
    {
        $packages = $license['packages'] ?? [];
        if ($packages === '*') {
            return true;
        }
        if (!is_array($packages)) {
            return false;
        }

        return in_array($slug, array_map('strval', $packages), true);
    }

    /**
     * @param array<string, mixed> $license
     */
    private function licenseIsActive(array $license): bool
    {
        if (($license['status'] ?? 'active') !== 'active') {
            return false;
        }

        $expiresAt = isset($license['expiresAt']) && is_scalar($license['expiresAt']) ? trim((string) $license['expiresAt']) : '';

        return $expiresAt === '' || strtotime($expiresAt) >= time();
    }

    /**
     * @return list<array<string, string>>
     */
    private function activations(string $licenseId): array
    {
        $activations = $this->cache->get('license-activations', $licenseId);
        if (!is_array($activations)) {
            return [];
        }

        return array_values(array_filter($activations, 'is_array'));
    }

    /**
     * @param list<array<string, string>> $activations
     */
    private function saveActivations(string $licenseId, array $activations): void
    {
        $this->cache->set('license-activations', $licenseId, $activations, self::ACTIVATION_TTL);
    }

    private function normalizeSiteUrl(string $siteUrl): string
    {
        $siteUrl = trim($siteUrl);
        if ($siteUrl === '') {
            return '';
        }

        $parts = parse_url($siteUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? '/' . trim($parts['path'], '/') : '';

        return rtrim($scheme . '://' . $host . $port . $path, '/');
    }

    /**
     * @param array<string, mixed> $license
     * @param array<string, string> $activation
     * @param list<array<string, string>> $activations
     * @return array<string, mixed>
     */
    private function activationResponse(array $license, array $activation, array $activations): array
    {
        return [
            'success' => true,
            'status' => 200,
            'message' => 'License activated successfully.',
            'license_id' => $license['id'],
            'activation_id' => $activation['id'],
            'site_url' => $activation['site_url'],
            'expires_at' => $license['expiresAt'] ?? null,
            'used_activations' => count($activations),
            'remaining_activations' => $this->remainingActivations($license, $activations),
        ];
    }

    /**
     * @param array<string, mixed> $license
     * @param list<array<string, string>> $activations
     */
    private function remainingActivations(array $license, array $activations): int|string
    {
        $limit = (int) ($license['activationLimit'] ?? 0);
        if ($limit <= 0) {
            return 'unlimited';
        }

        return max(0, $limit - count($activations));
    }
}
