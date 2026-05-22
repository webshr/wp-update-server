<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Http;

use RuntimeException;

final class StreamHttpClient implements HttpClientInterface
{
    private const MAX_REDIRECTS = 5;
    private const MAX_TRANSIENT_RETRIES = 2;

    public function get(string $url, array $headers = []): string
    {
        $headers[] = 'User-Agent: webshr/wp-update-server';

        return $this->request($url, $headers);
    }

    /**
     * @param list<string> $headers
     */
    private function request(string $url, array $headers, int $redirects = 0, int $retries = 0): string
    {
        if ($redirects > self::MAX_REDIRECTS) {
            throw new RuntimeException('Maximum redirects reached while fetching remote package.');
        }

        $this->validateUrl($url);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 15,
            ],
        ]);

        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            if ($retries < self::MAX_TRANSIENT_RETRIES) {
                $this->pauseBeforeRetry($retries);

                return $this->request($url, $headers, $redirects, $retries + 1);
            }

            throw new RuntimeException(sprintf('Unable to fetch "%s".', $url));
        }

        $statusCode = $this->statusCode($http_response_header);
        if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
            $location = $this->redirectLocation($http_response_header);
            if ($location === null) {
                throw new RuntimeException(sprintf('HTTP %d redirect without location while fetching "%s".', $statusCode, $url));
            }

            return $this->request($this->resolveUrl($url, $location), $headers, $redirects + 1, $retries);
        }

        if ($this->isTransientStatus($statusCode) && $retries < self::MAX_TRANSIENT_RETRIES) {
            $this->pauseBeforeRetry($retries);

            return $this->request($url, $headers, $redirects, $retries + 1);
        }

        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf('HTTP %d while fetching "%s": %s', $statusCode, $url, $this->errorMessage($body)));
        }

        return $body;
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        $statusLine = $headers[0] ?? '';
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $statusLine, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function isTransientStatus(int $statusCode): bool
    {
        return in_array($statusCode, [500, 502, 503, 504], true);
    }

    private function errorMessage(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['message']) && is_scalar($json['message'])) {
            return (string) $json['message'];
        }

        $text = html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', $body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim(substr($text, 0, 200)) ?: 'empty response body';
    }

    private function pauseBeforeRetry(int $retries): void
    {
        usleep(250000 * ($retries + 1));
    }

    /**
     * @param list<string> $headers
     */
    private function redirectLocation(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Location:') === 0) {
                return trim(substr($header, 9));
            }
        }

        return null;
    }

    private function resolveUrl(string $baseUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new RuntimeException('Invalid redirect base URL.');
        }

        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }

        $port = isset($base['port']) ? ':' . (int) $base['port'] : '';
        if (str_starts_with($location, '/')) {
            return $base['scheme'] . '://' . $base['host'] . $port . $location;
        }

        $path = isset($base['path']) ? dirname($base['path']) : '';

        return $base['scheme'] . '://' . $base['host'] . $port . rtrim($path, '/') . '/' . $location;
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Invalid remote package URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Remote package URLs must use HTTP or HTTPS.');
        }

        $host = (string) $parts['host'];
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            throw new RuntimeException(sprintf('Unable to resolve remote package host "%s".', $host));
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException(sprintf('Remote package host "%s" resolved to a private or reserved address.', $host));
            }
        }
    }
}
