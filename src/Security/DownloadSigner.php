<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Security;

final class DownloadSigner
{
    public function __construct(private readonly ?string $secret, private readonly int $ttl = 900)
    {
    }

    public function enabled(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /**
     * @param array<string, string> $params
     */
    public function sign(array $params): array
    {
        if (!$this->enabled()) {
            return $params;
        }

        $params['expires'] = (string) (time() + $this->ttl);
        $params['signature'] = $this->signature($params);

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $expected
     */
    public function validate(array $params, array $expected = []): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        $signature = isset($params['signature']) && is_scalar($params['signature']) ? (string) $params['signature'] : '';
        $expires = isset($params['expires']) && is_scalar($params['expires']) ? (int) $params['expires'] : 0;
        if ($signature === '' || $expires < time()) {
            return false;
        }

        $data = [];
        foreach ($params as $key => $value) {
            if ($key === 'signature' || !is_scalar($value)) {
                continue;
            }
            $data[(string) $key] = (string) $value;
        }

        foreach ($expected as $key => $value) {
            if (($data[$key] ?? null) !== $value) {
                return false;
            }
        }

        return hash_equals($this->signature($data), $signature);
    }

    /**
     * @param array<string, string> $params
     */
    private function signature(array $params): string
    {
        unset($params['signature']);
        ksort($params);

        return hash_hmac('sha256', http_build_query($params, '', '&'), (string) $this->secret);
    }
}
