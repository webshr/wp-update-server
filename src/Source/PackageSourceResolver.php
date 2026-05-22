<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Source;

use RuntimeException;
use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Package\PackageVersionCollection;
use Webshr\WpUpdateServer\Package\ResolvedPackage;

final class PackageSourceResolver
{
    /** @var list<PackageSourceInterface> */
    private array $sources;

    public function __construct(PackageSourceInterface ...$sources)
    {
        $this->sources = array_values($sources);
    }

    public function listVersions(PackageDefinition $package): PackageVersionCollection
    {
        return $this->sourceFor($package)->listVersions($package);
    }

    public function resolveVersion(PackageDefinition $package, string $version): ResolvedPackage
    {
        return $this->sourceFor($package)->resolveVersion($package, $version);
    }

    private function sourceFor(PackageDefinition $package): PackageSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->supports($package)) {
                return $source;
            }
        }

        throw new RuntimeException(sprintf('Unsupported package source "%s".', (string) ($package->source['kind'] ?? 'unknown')));
    }
}
