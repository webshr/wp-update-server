<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Package;

use RuntimeException;
use ZipArchive;

final class PackageInspector
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(string $archivePath, string $slug, string $type): array
    {
        $zip = $this->open($archivePath);
        $entries = $this->entries($zip);
        $topLevelDirectories = $this->topLevelDirectories($entries);

        $metadata = [
            'slug' => $slug,
            'last_updated' => gmdate('Y-m-d H:i:s', (int) filemtime($archivePath)),
        ];

        $readme = $this->findEntry($entries, static fn (string $name): bool => strtolower(basename($name)) === 'readme.txt');
        if ($readme !== null) {
            $metadata = array_replace_recursive($metadata, $this->parseReadme((string) $zip->getFromName($readme)));
        }

        if ($type === 'theme') {
            $style = $this->findEntry($entries, static fn (string $name): bool => strtolower(basename($name)) === 'style.css' && substr_count(trim($name, '/'), '/') <= 1);
            if ($style === null) {
                throw new RuntimeException(sprintf('Theme package "%s" does not contain a top-level style.css.', $slug));
            }
            $metadata = array_replace_recursive($metadata, $this->mapThemeHeaders($this->headers((string) $zip->getFromName($style), [
                'Name' => 'Theme Name',
                'Version' => 'Version',
                'ThemeURI' => 'Theme URI',
                'Description' => 'Description',
                'Author' => 'Author',
                'AuthorURI' => 'Author URI',
                'DetailsURI' => 'Details URI',
                'RequiresPHP' => 'Requires PHP',
            ])));
        } else {
            $pluginFile = $this->findPluginFile($zip, $entries);
            if ($pluginFile === null) {
                throw new RuntimeException(sprintf('Plugin package "%s" does not contain a plugin header.', $slug));
            }
            $metadata = array_replace_recursive($metadata, $this->mapPluginHeaders($this->headers((string) $zip->getFromName($pluginFile), [
                'Name' => 'Plugin Name',
                'Version' => 'Version',
                'PluginURI' => 'Plugin URI',
                'Description' => 'Description',
                'Author' => 'Author',
                'AuthorURI' => 'Author URI',
                'RequiresPHP' => 'Requires PHP',
                'UpdateURI' => 'Update URI',
            ])));
        }

        if (count($topLevelDirectories) === 1) {
            $metadata['package_directory'] = $topLevelDirectories[0];
        }

        $zip->close();

        return $metadata;
    }

    public function validate(string $archivePath, string $slug, string $type): PackageValidationResult
    {
        $errors = [];
        $warnings = [];

        if (!is_file($archivePath) || !is_readable($archivePath)) {
            return PackageValidationResult::invalid([sprintf('Archive "%s" is missing or unreadable.', $archivePath)]);
        }

        try {
            $zip = $this->open($archivePath);
            $entries = $this->entries($zip);
            $topLevelDirectories = $this->topLevelDirectories($entries);

            if ($topLevelDirectories === []) {
                $errors[] = 'Archive must contain one top-level directory.';
            } elseif (!in_array($slug, $topLevelDirectories, true)) {
                $errors[] = sprintf('Archive top-level directory must match slug "%s". Found: %s.', $slug, implode(', ', $topLevelDirectories));
            }

            if ($type === 'theme') {
                $style = $this->findEntry($entries, static fn (string $name): bool => strtolower($name) === strtolower($slug . '/style.css'));
                if ($style === null) {
                    $errors[] = sprintf('Theme archive must contain "%s/style.css".', $slug);
                }
            } elseif ($this->findPluginFile($zip, $entries) === null) {
                $errors[] = 'Plugin archive must contain a top-level PHP file with a Plugin Name header.';
            }

            $zip->close();
        } catch (\Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        return $errors === [] ? PackageValidationResult::valid($warnings) : PackageValidationResult::invalid($errors, $warnings);
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
     * @return list<string>
     */
    private function topLevelDirectories(array $entries): array
    {
        $directories = [];
        foreach ($entries as $entry) {
            $parts = explode('/', $entry);
            if (count($parts) > 1 && $parts[0] !== '') {
                $directories[$parts[0]] = true;
            }
        }

        return array_keys($directories);
    }

    /**
     * @param list<string> $entries
     * @param callable(string): bool $matches
     */
    private function findEntry(array $entries, callable $matches): ?string
    {
        foreach ($entries as $entry) {
            if ($matches($entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<string> $entries
     */
    private function findPluginFile(ZipArchive $zip, array $entries): ?string
    {
        foreach ($entries as $entry) {
            if (!str_ends_with(strtolower($entry), '.php') || substr_count($entry, '/') > 1) {
                continue;
            }

            $headers = $this->headers((string) $zip->getFromName($entry), ['Name' => 'Plugin Name']);
            if (($headers['Name'] ?? '') !== '') {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $headerMap
     * @return array<string, string>
     */
    private function headers(string $contents, array $headerMap): array
    {
        $contents = str_replace("\r", "\n", substr($contents, 0, 8192));
        $headers = [];
        foreach ($headerMap as $key => $label) {
            $found = preg_match('/^[ \t\/*#@]*' . preg_quote($label, '/') . ':(.*)$/mi', $contents, $matches);
            $headers[$key] = $found === 1 ? trim((string) preg_replace("/\s*(?:\*\/|\?>).*/", '', $matches[1])) : '';
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function mapPluginHeaders(array $headers): array
    {
        return [
            'name' => $headers['Name'] ?? null,
            'version' => $headers['Version'] ?? null,
            'homepage' => $headers['PluginURI'] ?? null,
            'description' => $headers['Description'] ?? null,
            'author' => $headers['Author'] ?? null,
            'author_homepage' => $headers['AuthorURI'] ?? null,
            'requires_php' => $headers['RequiresPHP'] ?? null,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function mapThemeHeaders(array $headers): array
    {
        return [
            'name' => $headers['Name'] ?? null,
            'version' => $headers['Version'] ?? null,
            'homepage' => $headers['ThemeURI'] ?? null,
            'description' => $headers['Description'] ?? null,
            'author' => $headers['Author'] ?? null,
            'author_homepage' => $headers['AuthorURI'] ?? null,
            'details_url' => ($headers['DetailsURI'] ?? '') !== '' ? $headers['DetailsURI'] : ($headers['ThemeURI'] ?? null),
            'requires_php' => $headers['RequiresPHP'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseReadme(string $contents): array
    {
        $lines = preg_split('/\R/', trim($contents)) ?: [];
        if ($lines === [] || preg_match('/^===\s*(.+?)\s*===/', (string) array_shift($lines), $nameMatches) !== 1) {
            return [];
        }

        $metadata = ['name' => $nameMatches[1]];
        $map = [
            'Requires at least' => 'requires',
            'Tested up to' => 'tested',
            'Requires PHP' => 'requires_php',
            'Stable tag' => 'stable',
        ];

        while ($lines !== []) {
            $line = array_shift($lines);
            if (trim((string) $line) === '') {
                break;
            }
            if (str_contains((string) $line, ':')) {
                [$key, $value] = array_map('trim', explode(':', (string) $line, 2));
                if (isset($map[$key])) {
                    $metadata[$map[$key]] = $value;
                }
            }
        }

        if ($lines !== []) {
            $metadata['short_description'] = trim((string) array_shift($lines));
        }

        $sections = [];
        $current = null;
        $buffer = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*==\s+(.+?)\s+==\s*$/', (string) $line, $matches) === 1) {
                if ($current !== null) {
                    $sections[$this->sectionKey($current)] = $this->markdown(trim(implode("\n", $buffer)));
                }
                $current = $matches[1];
                $buffer = [];
                continue;
            }
            $buffer[] = (string) $line;
        }
        if ($current !== null) {
            $sections[$this->sectionKey($current)] = $this->markdown(trim(implode("\n", $buffer)));
        }

        if ($sections !== []) {
            $metadata['sections'] = $sections;
        }

        return $metadata;
    }

    private function sectionKey(string $name): string
    {
        return strtolower(str_replace(' ', '_', trim($name)));
    }

    private function markdown(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (class_exists(\Parsedown::class)) {
            return \Parsedown::instance()->text($text);
        }

        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
