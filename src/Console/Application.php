<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Console;

use RuntimeException;
use Throwable;
use Webshr\WpUpdateServer\Cache\FilesystemCache;
use Webshr\WpUpdateServer\Config\ConfigValidationResult;
use Webshr\WpUpdateServer\Config\ConfigValidator;
use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Package\PackageInspector;
use Webshr\WpUpdateServer\Package\PackageValidator;
use Webshr\WpUpdateServer\Package\VersionSelector;
use Webshr\WpUpdateServer\Server\ServerFactory;
use Webshr\WpUpdateServer\Source\FilesystemPackageSource;
use Webshr\WpUpdateServer\Source\GitHubReleasePackageSource;
use Webshr\WpUpdateServer\Source\PackageSourceResolver;
use Webshr\WpUpdateServer\Support\Environment;

final class Application
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        Environment::load($this->root);

        $args = $this->positionalArgs($argv);
        $group = $args[0] ?? 'help';
        $action = $args[1] ?? null;
        $configPath = $this->optionValue($argv, '--config');

        if ($group === 'help' || in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            $this->printHelp();

            return 0;
        }

        $factory = new ServerFactory($this->root);

        try {
            $config = $factory->config($configPath);

            if ($group . ' ' . (string) $action === 'config validate') {
                $result = (new ConfigValidator())->validate($config);
                $this->printConfigValidation($result, count($config->packages->all()));

                return $result->valid() ? 0 : 1;
            }

            $cache = new FilesystemCache($config->server->cacheDir);
            $inspector = new PackageInspector();
            $sources = new PackageSourceResolver(
                new FilesystemPackageSource($config->server, $inspector),
                new GitHubReleasePackageSource($config->server, $cache, $inspector)
            );
            $validator = new PackageValidator($inspector);
            $selector = new VersionSelector();

            return $this->runCommand(
                $group,
                (string) $action,
                $args,
                $argv,
                $sources,
                $validator,
                $selector,
                $cache,
                $config->server->cacheDir,
                $config->packages->all()
            );
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param array<int, string> $args
     * @param array<int, string> $argv
     * @param array<string, PackageDefinition> $packages
     */
    private function runCommand(
        string $group,
        string $action,
        array $args,
        array $argv,
        PackageSourceResolver $sources,
        PackageValidator $validator,
        VersionSelector $selector,
        FilesystemCache $cache,
        string $cacheDir,
        array $packages
    ): int {
        switch ($group . ' ' . $action) {
            case 'package list':
                foreach ($packages as $slug => $package) {
                    $sourceKind = (string) ($package->source['kind'] ?? 'filesystem');
                    $license = $package->license !== [] ? 'licensed' : 'public';
                    echo $slug . "\t" . $package->type . "\t" . $sourceKind . "\t" . $license . "\n";
                }

                return 0;

            case 'package get':
                $package = $this->packageFromArgs($args, $packages, 'Usage: wpus package get <slug> [--version=<version>] [--channel=<channel>]');
                $version = $this->optionValue($argv, '--version');
                $channel = $this->optionValue($argv, '--channel');

                if ($version === null && $channel === null) {
                    foreach ($sources->listVersions($package)->all() as $packageVersion) {
                        echo $packageVersion->version . "\t" . ($packageVersion->prerelease ? 'prerelease' : 'stable') . "\t" . ($packageVersion->releaseDate ?? '-') . "\n";
                    }

                    return 0;
                }

                if ($version === null) {
                    $selected = $selector->select($package, $sources->listVersions($package), null, (string) $channel);
                    if ($selected === null) {
                        throw new RuntimeException('No matching package version is available.');
                    }
                    $version = $selected->version;
                }

                echo json_encode($sources->resolveVersion($package, $version)->metadata->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

                return 0;

            case 'package validate':
                return $this->validatePackages($args, $argv, $packages, $sources, $validator);

            case 'cache warm':
                foreach ($packages as $slug => $package) {
                    foreach ($sources->listVersions($package)->all() as $version) {
                        $sources->resolveVersion($package, $version->version);
                        echo $slug . '@' . $version->version . ": warmed\n";
                    }
                }

                return 0;

            case 'cache flush':
                $namespace = $args[2] ?? null;
                $cache->clear($namespace);
                echo 'Cache flushed' . ($namespace ? " for {$namespace}" : '') . "\n";

                return 0;

            case 'cache status':
                foreach (['github-release-metadata', 'github-release-list', 'github-assets', 'normalized-packages', 'rate-limit'] as $namespace) {
                    $dir = rtrim($cacheDir, "/\\") . DIRECTORY_SEPARATOR . $namespace;
                    $files = is_dir($dir) ? count(glob($dir . DIRECTORY_SEPARATOR . '*') ?: []) : 0;
                    echo $namespace . "\t" . $files . "\n";
                }

                return 0;

            case 'help ':
            default:
                $this->printHelp();

                return 0;
        }
    }

    /**
     * @param array<int, string> $args
     * @param array<string, PackageDefinition> $packages
     */
    private function packageFromArgs(array $args, array $packages, string $usage): PackageDefinition
    {
        $slug = $args[2] ?? null;
        if ($slug === null) {
            throw new RuntimeException($usage);
        }
        if (!isset($packages[$slug])) {
            throw new RuntimeException(sprintf('Unknown package "%s".', $slug));
        }

        return $packages[$slug];
    }

    /**
     * @param array<int, string> $args
     * @param array<int, string> $argv
     * @param array<string, PackageDefinition> $packages
     */
    private function validatePackages(
        array $args,
        array $argv,
        array $packages,
        PackageSourceResolver $sources,
        PackageValidator $validator
    ): int {
        $slug = $args[2] ?? null;
        $failed = 0;

        if ($slug === null) {
            foreach ($packages as $packageSlug => $package) {
                $failed += $this->validatePackage($packageSlug, $package, $sources, $validator);
            }

            return $failed > 0 ? 1 : 0;
        }

        $package = $this->packageFromArgs($args, $packages, 'Usage: wpus package validate [<slug>] [--version=<version>]');
        $version = $this->optionValue($argv, '--version');
        $versions = $version !== null
            ? [$sources->listVersions($package)->get($version)]
            : $sources->listVersions($package)->all();

        foreach ($versions as $packageVersion) {
            $failed += $this->validatePackageVersion($slug, $package, $packageVersion->version, $sources, $validator);
        }

        return $failed > 0 ? 1 : 0;
    }

    private function validatePackage(string $slug, PackageDefinition $package, PackageSourceResolver $sources, PackageValidator $validator): int
    {
        $failed = 0;
        foreach ($sources->listVersions($package)->all() as $version) {
            $failed += $this->validatePackageVersion($slug, $package, $version->version, $sources, $validator);
        }

        return $failed;
    }

    private function validatePackageVersion(
        string $slug,
        PackageDefinition $package,
        string $version,
        PackageSourceResolver $sources,
        PackageValidator $validator
    ): int {
        $resolved = $sources->resolveVersion($package, $version);
        $result = $validator->validate($resolved->archivePath, $slug, $package->type);
        echo $slug . '@' . $version . ': ' . ($result->valid ? 'OK' : 'FAILED') . "\n";
        foreach ($result->errors as $error) {
            echo '  - ' . $error . "\n";
        }

        return $result->valid ? 0 : 1;
    }

    /**
     * @param array<int, string> $argv
     * @return array<int, string>
     */
    private function positionalArgs(array $argv): array
    {
        $positionals = [];
        for ($index = 1, $count = count($argv); $index < $count; $index++) {
            $arg = $argv[$index];
            if (str_contains($arg, '=')) {
                [$name] = explode('=', $arg, 2);
                if (str_starts_with($name, '--')) {
                    continue;
                }
            }
            if (str_starts_with($arg, '--')) {
                if (isset($argv[$index + 1]) && !str_starts_with($argv[$index + 1], '--')) {
                    $index++;
                }
                continue;
            }
            $positionals[] = $arg;
        }

        return $positionals;
    }

    /**
     * @param array<int, string> $argv
     */
    private function optionValue(array $argv, string $name): ?string
    {
        foreach ($argv as $index => $arg) {
            if ($arg === $name && isset($argv[$index + 1])) {
                return $argv[$index + 1];
            }

            if (str_starts_with($arg, $name . '=')) {
                return substr($arg, strlen($name) + 1);
            }
        }

        return null;
    }

    private function printConfigValidation(ConfigValidationResult $result, int $packageCount): void
    {
        echo $result->valid() ? "Config OK\n" : "Config FAILED\n";
        echo $packageCount . " packages registered\n";

        foreach ($result->errors as $error) {
            echo 'ERROR: ' . $error . "\n";
        }

        foreach ($result->warnings as $warning) {
            echo 'WARN: ' . $warning . "\n";
        }
    }

    private function printHelp(): void
    {
        echo "Usage: wpus <group> <command> [args] [--config=<path>]\n";
        echo "Commands:\n";
        echo "  config validate\n";
        echo "  package list\n";
        echo "  package get <slug> [--version=<version>] [--channel=<channel>]\n";
        echo "  package validate [<slug>] [--version=<version>]\n";
        echo "  cache warm\n";
        echo "  cache flush [<namespace>]\n";
        echo "  cache status\n";
    }
}
