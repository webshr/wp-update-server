<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Auth;

use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Http\Request;
use Webshr\WpUpdateServer\License\LicenseManager;

final class LicenseAuthorizationProvider implements AuthorizationProviderInterface
{
    public function __construct(private readonly LicenseManager $licenses)
    {
    }

    public function canViewMetadata(PackageDefinition $package, Request $request): bool
    {
        return true;
    }

    public function canDownload(PackageDefinition $package, Request $request): bool
    {
        return $this->licenses->canAccess(
            $package,
            $request->queryString('license_key'),
            $request->queryString('activation_id'),
            $request->queryString('site_url') ?: $request->siteUrl()
        );
    }
}
