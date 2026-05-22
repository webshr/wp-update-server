<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Source;

use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Package\PackageVersionCollection;
use Webshr\WpUpdateServer\Package\ResolvedPackage;

interface PackageSourceInterface
{
    public function supports(PackageDefinition $package): bool;

    public function listVersions(PackageDefinition $package): PackageVersionCollection;

    public function resolveVersion(PackageDefinition $package, string $version): ResolvedPackage;
}
