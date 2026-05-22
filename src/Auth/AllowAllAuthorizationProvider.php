<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Auth;

use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Http\Request;

final class AllowAllAuthorizationProvider implements AuthorizationProviderInterface
{
    public function canViewMetadata(PackageDefinition $package, Request $request): bool
    {
        return true;
    }

    public function canDownload(PackageDefinition $package, Request $request): bool
    {
        return true;
    }
}
