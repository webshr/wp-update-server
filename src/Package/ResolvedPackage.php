<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Package;

final class ResolvedPackage
{
    public function __construct(
        public readonly string $slug,
        public readonly string $type,
        public readonly string $version,
        public readonly string $archivePath,
        public readonly PackageMetadata $metadata,
        public readonly bool $temporary = false
    ) {
    }
}
