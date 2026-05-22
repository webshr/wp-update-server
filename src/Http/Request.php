<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly array $query,
        public readonly array $headers,
        public readonly string $method,
        public readonly string $clientIp,
        public readonly string $uri,
        public readonly array $body = []
    ) {
    }

    /**
     * @param list<string> $trustedProxies
     * @param list<string> $trustedProxyHeaders
     */
    public static function fromGlobals(array $trustedProxies = [], array $trustedProxyHeaders = ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP']): self
    {
        $headers = self::headersFromServer($_SERVER);

        return new self(
            $_GET,
            $headers,
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::clientIpFromServer($_SERVER, $headers, $trustedProxies, $trustedProxyHeaders),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            self::bodyFromGlobals()
        );
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function inputString(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function header(string $name, string $default = ''): string
    {
        $normalized = self::normalizeHeaderName($name);

        return $this->headers[$normalized] ?? $default;
    }

    public function slug(): string
    {
        return preg_replace('/[^A-Za-z0-9._+\-]/', '', $this->queryString('slug'));
    }

    public function action(): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '', $this->queryString('action'));
    }

    /**
     * @return list<string>
     */
    public function pathSegments(): array
    {
        $path = parse_url($this->uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
    }

    public function wpVersion(): ?string
    {
        $parsed = $this->parseWordPressUserAgent();

        return $parsed['version'] ?? null;
    }

    public function siteUrl(): ?string
    {
        $parsed = $this->parseWordPressUserAgent();

        return $parsed['url'] ?? null;
    }

    /**
     * @return array{version?: string, url?: string}
     */
    private function parseWordPressUserAgent(): array
    {
        $userAgent = $this->header('User-Agent');
        if (preg_match('@WordPress/(?P<version>\d[^;]*?);\s+(?P<url>https?://.+?)(?:\s|;|$)@i', $userAgent, $matches) === 1) {
            return ['version' => $matches['version'], 'url' => $matches['url']];
        }
        if (preg_match('@WordPress\.com;\s+(?P<url>https?://.+?)(?:\s|;|$)@i', $userAgent, $matches) === 1) {
            return ['url' => $matches['url']];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $name = strtoupper((string) $key);
            if (str_starts_with($name, 'HTTP_')) {
                $name = substr($name, 5);
            } elseif (!in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'AUTH_TYPE'], true)) {
                continue;
            }

            $headers[self::normalizeHeaderName($name)] = (string) $value;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodyFromGlobals(): array
    {
        if ($_POST !== []) {
            return $_POST;
        }

        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_contains(strtolower($contentType), 'application/json')) {
            return [];
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        if (strlen($raw) > 10240) {
            throw new BadRequestException('Request body too large.');
        }

        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestException('Invalid JSON in request body.');
        }

        if ($decoded instanceof \stdClass) {
            return get_object_vars($decoded);
        }

        throw new BadRequestException('JSON request body must be an object.');
    }

    private static function normalizeHeaderName(string $name): string
    {
        $name = strtolower(str_replace('_', '-', $name));

        return implode('-', array_map('ucfirst', explode('-', $name)));
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $headers
     * @param list<string> $trustedProxies
     * @param list<string> $trustedProxyHeaders
     */
    private static function clientIpFromServer(array $server, array $headers, array $trustedProxies, array $trustedProxyHeaders): string
    {
        $remoteAddress = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!self::proxyTrusted($remoteAddress, $trustedProxies)) {
            return $remoteAddress;
        }

        foreach ($trustedProxyHeaders as $headerName) {
            $header = $headers[self::normalizeHeaderName($headerName)] ?? '';
            foreach (explode(',', $header) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return $remoteAddress;
    }

    /**
     * @param list<string> $trustedProxies
     */
    private static function proxyTrusted(string $remoteAddress, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $trustedProxy) {
            if ($trustedProxy === $remoteAddress || self::ipInCidr($remoteAddress, $trustedProxy)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $address, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $addressBinary = inet_pton($address);
        $networkBinary = inet_pton($network);
        if ($addressBinary === false || $networkBinary === false || strlen($addressBinary) !== strlen($networkBinary)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $maxPrefix = strlen($addressBinary) * 8;
        if ((string) $prefixLength !== $prefix || $prefixLength < 0 || $prefixLength > $maxPrefix) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;
        if ($fullBytes > 0 && substr($addressBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($addressBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
