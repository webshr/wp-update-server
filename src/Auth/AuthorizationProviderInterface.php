<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Auth;

use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Http\Request;

interface AuthorizationProviderInterface
{
    public function canViewMetadata(PackageDefinition $package, Request $request): bool;

    public function canDownload(PackageDefinition $package, Request $request): bool;
}
