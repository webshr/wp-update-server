<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Package;

use RuntimeException;
use ZipArchive;

final class PackageArchiveNormalizer
{
    public function normalizeTo(string $archivePath, string $targetPath, string $slug): string
    {
        if (!is_file($archivePath) || !is_readable($archivePath)) {
            throw new RuntimeException(sprintf('Archive "%s" is missing or unreadable.', $archivePath));
        }

        $source = $this->open($archivePath);
        $entries = $this->entries($source);
        if ($this->alreadyNormalized($entries, $slug)) {
            $source->close();

            return $archivePath;
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $source->close();

            throw new RuntimeException(sprintf('Unable to create normalized package directory "%s".', $targetDir));
        }

        $tmpPath = $targetPath . '.tmp';
        $target = new ZipArchive();
        if ($target->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $source->close();

            throw new RuntimeException(sprintf('Unable to create normalized ZIP archive "%s".', $tmpPath));
        }

        $stripPrefix = $this->singleTopLevelDirectory($entries);
        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            $normalizedName = $this->normalizedEntryName($name, $slug, $stripPrefix);
            if ($normalizedName === null) {
                continue;
            }

            if (str_ends_with($normalizedName, '/')) {
                $target->addEmptyDir(rtrim($normalizedName, '/'));
                continue;
            }

            $contents = $source->getFromIndex($i);
            if ($contents === false) {
                $target->close();
                $source->close();
                @unlink($tmpPath);

                throw new RuntimeException(sprintf('Unable to read ZIP entry "%s".', $name));
            }

            $target->addFromString($normalizedName, $contents);
        }

        $target->close();
        $source->close();

        if (!rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);

            throw new RuntimeException(sprintf('Unable to move normalized ZIP archive to "%s".', $targetPath));
        }

        return $targetPath;
    }

    private function open(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException(sprintf('Unable to open ZIP archive "%s".', $archivePath));
        }

        return $zip;
    }

    /**
     * @return list<string>
     */
    private function entries(ZipArchive $zip): array
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $entries[] = trim(str_replace('\\', '/', $name), '/');
            }
        }

        return array_values(array_filter($entries));
    }

    /**
     * @param list<string> $entries
     */
    private function alreadyNormalized(array $entries, string $slug): bool
    {
        foreach ($entries as $entry) {
            if ($entry !== $slug && !str_starts_with($entry, $slug . '/')) {
                return false;
            }
        }

        return $entries !== [];
    }

    /**
     * @param list<string> $entries
     */
    private function singleTopLevelDirectory(array $entries): ?string
    {
        $directories = [];
        foreach ($entries as $entry) {
            $parts = explode('/', $entry, 2);
            if (count($parts) === 1) {
                return null;
            }
            $directories[$parts[0]] = true;
        }

        return count($directories) === 1 ? (string) array_key_first($directories) : null;
    }

    private function normalizedEntryName(string $name, string $slug, ?string $stripPrefix): ?string
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        if ($name === '' || str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
            return null;
        }

        if ($stripPrefix !== null && ($name === $stripPrefix || str_starts_with($name, $stripPrefix . '/'))) {
            $name = ltrim(substr($name, strlen($stripPrefix)), '/');
        }

        return $name === '' ? null : $slug . '/' . $name;
    }
}
