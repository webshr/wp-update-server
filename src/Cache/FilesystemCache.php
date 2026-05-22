<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Cache;

use RuntimeException;

final class FilesystemCache implements CacheInterface
{
    public function __construct(private readonly string $cacheDir)
    {
        $this->ensureDirectory($this->cacheDir);
    }

    public function get(string $namespace, string $key): mixed
    {
        $path = $this->path($namespace, $key);
        if (! is_file($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return null;
        }

        try {
            // shared lock for safe concurrent reads
            if (! flock($handle, LOCK_SH)) {
                fclose($handle);
                return null;
            }

            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            $payload = json_decode((string) $contents, true);
            if (! is_array($payload) || ! isset($payload['expires_at'])) {
                $this->delete($namespace, $key);
                return null;
            }

            if ((int) $payload['expires_at'] < time()) {
                $this->delete($namespace, $key);
                return null;
            }

            return $payload['value'] ?? null;
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
    }

    public function set(string $namespace, string $key, mixed $value, int $ttl): void
    {
        $dir = $this->namespaceDir($namespace);
        $this->ensureDirectory($dir);

        $payload = [
            'expires_at' => time() + max(0, $ttl),
            'value'      => $value,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode cache payload as JSON.');
        }

        $path = $this->path($namespace, $key);
        $tmp  = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            $written = file_put_contents($tmp, $encoded, LOCK_EX);
            if ($written === false || $written !== strlen($encoded)) {
                throw new RuntimeException(sprintf('Unable to write cache file "%s".', $tmp));
            }

            @chmod($tmp, 0600);

            if (! @rename($tmp, $path)) {
                throw new RuntimeException(sprintf('Unable to replace cache file "%s".', $path));
            }

            @chmod($path, 0600);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function delete(string $namespace, string $key): void
    {
        $path = $this->path($namespace, $key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function clear(?string $namespace = null): void
    {
        $dir = $namespace === null ? $this->cacheDir : $this->namespaceDir($namespace);
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }

    public function artifactPath(string $namespace, string $key, string $extension): string
    {
        $dir = $this->namespaceDir($namespace);
        $this->ensureDirectory($dir);

        $path = $dir . DIRECTORY_SEPARATOR . sha1($key) . '.' . ltrim($extension, '.');
        if (is_file($path)) {
            @chmod($path, 0600);
        }

        return $path;
    }

    private function path(string $namespace, string $key): string
    {
        return $this->namespaceDir($namespace) . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }

    private function namespaceDir(string $namespace): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $namespace);

        return rtrim($this->cacheDir, "/\\") . DIRECTORY_SEPARATOR . $safe;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            @chmod($dir, 0700);
            return;
        }
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create cache directory "%s".', $dir));
        }
        @chmod($dir, 0700);
    }
}
