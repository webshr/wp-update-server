<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Package;

final class PackageValidator
{
    public function __construct(private readonly PackageInspector $inspector)
    {
    }

    public function validate(string $archivePath, string $slug, string $type): PackageValidationResult
    {
        return $this->inspector->validate($archivePath, $slug, $type);
    }
}
