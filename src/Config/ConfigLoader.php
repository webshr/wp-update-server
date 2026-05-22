<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Config;

use RuntimeException;

final class ConfigLoader
{
    public function __construct(private readonly string $rootDir)
    {
    }

    public function load(?string $path = null): Config
    {
        $data = $path !== null ? $this->loadFile($path) : $this->loadDefaultConfig();
        $server = ServerConfig::fromArray($data, $this->rootDir);
        $packages = $data['packages'] ?? [];
        if (!is_array($packages)) {
            throw new RuntimeException('The "packages" config value must be an object.');
        }
        $licenses = $data['licenses'] ?? [];
        if (!is_array($licenses)) {
            throw new RuntimeException('The "licenses" config value must be an object.');
        }

        return new Config($server, new PackageRegistry($packages), $licenses);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDefaultConfig(): array
    {
        $aggregatePath = $this->rootDir . DIRECTORY_SEPARATOR . 'update-server.php';
        if (is_file($aggregatePath)) {
            return $this->loadFile($aggregatePath);
        }

        return $this->loadConventionalConfig();
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Config file "%s" is not readable.', $path));
        }

        if (str_ends_with($path, '.php')) {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }

            $data = require $path;
            if (!is_array($data)) {
                throw new RuntimeException('PHP config must return an array.');
            }

            return $data;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read config file "%s".', $path));
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('JSON config is invalid or does not contain an object.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConventionalConfig(): array
    {
        $configDir = $this->rootDir . DIRECTORY_SEPARATOR . 'config';
        $serverPath = $configDir . DIRECTORY_SEPARATOR . 'server.php';
        if (!is_file($serverPath) || !is_readable($serverPath)) {
            throw new RuntimeException('No server config found. Create config/server.php.');
        }

        return [
            'server' => $this->loadFile($serverPath),
            'packages' => $this->loadOptionalConfig($configDir . DIRECTORY_SEPARATOR . 'packages.php'),
            'licenses' => $this->loadOptionalConfig($configDir . DIRECTORY_SEPARATOR . 'licenses.php'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOptionalConfig(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        return $this->loadFile($path);
    }
}
