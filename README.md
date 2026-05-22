# Webshr WP Update Server

A modern, headless, standalone PHP update server for WordPress plugins and themes.

It serves WordPress-compatible update metadata and package downloads without adding a WordPress admin UI or requiring a WordPress installation. Filesystem ZIP packages are supported out of the box, and GitHub Releases can be used as an optional remote package source.

## Requirements

- PHP 8.2+
- Composer
- PHP extensions: `json`, `zip`
- Writable `storage/packages/`, `storage/cache/`, and `storage/logs/` directories

## Install

Recommended: start from the ready-to-run app skeleton.

```bash
composer create-project webshr/wp-update-server-app my-update-server
cd my-update-server
```

The app skeleton lives at [webshr/wp-update-server-app](https://github.com/webshr/wp-update-server-app). It includes the deployable project layout: `public/index.php`, root `wpus`, `config/`, `storage/`, and `.env.example`.

Create your environment file and configure the server:

```bash
cp .env.example .env
```

Edit `.env`, then add your packages in `config/packages.php`.

Validate the app:

```bash
php wpus config validate
php wpus package list
```

Point your web server document root to:

```text
my-update-server/public
```

For local testing:

```bash
php -S localhost:8000 -t public public/index.php
```

Open:

```text
http://localhost:8000/metadata/my-plugin
```

For installed clients, include their current version:

```text
http://localhost:8000/metadata/my-plugin?installed_version=1.2.0
```

## Library Usage

If you are embedding this library into your own project instead of using the app skeleton, install it with Composer:

```bash
composer require webshr/wp-update-server
```

Copy the app stubs into your project root:

```text
stubs/.env.example          -> .env
stubs/public/index.php      -> public/index.php
stubs/config/*              -> config/
stubs/storage/*             -> storage/
stubs/update-server.php     -> update-server.php
```

The copied stubs are bootable files. In particular, `stubs/config/packages.php` and `stubs/config/licenses.php` should become `config/packages.php` and `config/licenses.php`, not `*.example.php` files.

When using the library directly, the Composer binary is:

```bash
vendor/bin/wpus config validate
```

The `php wpus ...` command is provided by the app skeleton's root `wpus` file. If you want that command shape in a custom app, copy or create a root `wpus` launcher there.

## Configuration

By default, the server reads configuration from the `config/` directory. The HTTP/server settings live in `config/server.php`, packages in `config/packages.php`, and licenses in `config/licenses.php`. `config/server.php` is required. `config/packages.php` and `config/licenses.php` are optional and default to empty arrays when they do not exist.

For custom wiring, copy `stubs/update-server.php` to a root-level `update-server.php` aggregate config and edit it. When present, `update-server.php` overrides the conventional `config/*.php` loading. A path passed with `--config` takes precedence over both.

Example `config/server.php`:

```php
<?php

declare(strict_types=1);

use Webshr\WpUpdateServer\Support\Env;

$storageDir = Env::string('WP_UPDATE_SERVER_STORAGE_DIR', 'storage');

return [
    // Canonical base URL (can be overridden by env `WP_UPDATE_SERVER_BASE_URL`)
    'baseUrl'               => Env::string('WP_UPDATE_SERVER_BASE_URL', 'http://localhost:8000/'),

    // Local paths (relative to project root)
    'storageDir'            => $storageDir,
    'cacheDir'              => Env::string('WP_UPDATE_SERVER_CACHE_DIR', $storageDir . '/cache'),
    'packageDir'            => Env::string('WP_UPDATE_SERVER_PACKAGE_DIR', $storageDir . '/packages'),
    'packageAssetDir'       => Env::string('WP_UPDATE_SERVER_PACKAGE_ASSET_DIR', 'public/package-assets'),
    'logDir'                => Env::string('WP_UPDATE_SERVER_LOG_DIR', $storageDir . '/logs'),

    // Signed download configuration (toggle with env var)
    'signDownloads'         => Env::bool('WP_UPDATE_SERVER_SIGN_DOWNLOADS', false),
    'downloadSecret'        => Env::string('WP_UPDATE_SERVER_SECRET'),

    // Defaults for caches, signatures and rate limiting
    'defaultCacheTtl'       => Env::int('WP_UPDATE_SERVER_CACHE_TTL', 3600),
    'downloadSignatureTtl'  => Env::int('WP_UPDATE_SERVER_DOWNLOAD_SIGNATURE_TTL', 900),
    'downloadLimit'         => Env::int('WP_UPDATE_SERVER_DOWNLOAD_LIMIT', 60),
    'downloadWindowSeconds' => Env::int('WP_UPDATE_SERVER_DOWNLOAD_WINDOW_SECONDS', 3600),

    // Optional reverse proxy support. Forwarded IP headers are ignored unless REMOTE_ADDR matches this list.
    'trustedProxies'        => Env::list('WP_UPDATE_SERVER_TRUSTED_PROXIES'),
    'trustedProxyHeaders'   => Env::list(
        'WP_UPDATE_SERVER_TRUSTED_PROXY_HEADERS',
        ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP']
    ),

    // Rotate JSON log files by size.
    'logMaxBytes'           => Env::int('WP_UPDATE_SERVER_LOG_MAX_BYTES', 10485760),
];
```

Packages and licenses are provided via `config/packages.php` and `config/licenses.php` respectively. These files return arrays that map package slugs and license IDs to their configuration. The stubs at `stubs/config/packages.php` and `stubs/config/licenses.php` are bootable defaults with commented examples.

Put a ZIP archive at `storage/packages/my-plugin/1.3.0/my-plugin.zip`. The ZIP must contain exactly one top-level directory matching the slug:

```text
my-plugin/
  my-plugin.php
  readme.txt
```

Validate the setup:

```bash
vendor/bin/wpus config validate
vendor/bin/wpus package validate
```

`config validate` checks signing configuration, writable storage paths, package source definitions, license shape, and trusted proxy settings. It prints warnings for non-fatal operator issues and exits nonzero on errors.

## Endpoint Design

The server exposes a fresh path-based API:

```text
GET /metadata/{slug}
GET /download/{slug}/{version}
POST /cache/clear
GET /cache/clear
```

`/metadata/{slug}` selects the best available update version and returns JSON metadata including version, name, requirements, sections, icons/banners, and a `download_url` when the request is authorized.

`/download/{slug}/{version}` streams the selected package ZIP through this server with safe download headers. GitHub packages are proxied through this endpoint; clients never need to receive the GitHub asset URL.

There is also a cache invalidation endpoint:

```text
POST /cache/clear
GET /cache/clear?namespace=github-release-metadata
```

When download signing is enabled, cache invalidation must include a valid signed query.

## Filesystem Packages

Versioned packages:

```php
'packages' => [
    'my-plugin' => [
        'type' => 'plugin',
        'versions' => [
            '1.2.0' => [
                'source' => [
                    'kind' => 'filesystem',
                    'path' => 'packages/my-plugin/1.2.0/my-plugin.zip',
                ],
                'metadata' => [
                    'requires' => '6.1',
                    'tested' => '6.5',
                    'requires_php' => '7.4',
                ],
            ],
            '1.3.0' => [
                'source' => [
                    'kind' => 'filesystem',
                    'path' => 'packages/my-plugin/1.3.0/my-plugin.zip',
                ],
                'metadata' => [
                    'requires' => '6.3',
                    'tested' => '6.8',
                    'requires_php' => '8.1',
                ],
            ],
        ],
    ],
],
```

Directory scan mode:

```php
'packages' => [
    'my-plugin' => [
        'type' => 'plugin',
        'source' => [
            'kind' => 'filesystem',
            'path' => 'packages/my-plugin',
            'versionPattern' => '/^my-plugin-(?<version>.+)\.zip$/',
        ],
    ],
],
```

The server extracts metadata from:

- plugin headers in a top-level PHP file
- theme headers in `style.css`
- WordPress.org-style `readme.txt` sections

Global package metadata overrides extracted metadata. Per-version metadata overrides both.

Single-file packages still work as a convenience, but versioned packages are recommended for production.

## GitHub Releases Packages

Public release asset:

```php
'pro-theme' => [
    'type' => 'theme',
    'source' => [
        'kind' => 'github-release',
        'repo' => 'vendor/pro-theme',
        'asset' => 'pro-theme.zip',
        'versionFrom' => 'tag_name',
        'releaseStrategy' => 'versions',
        'cacheTtl' => 900,
    ],
],
```

Asset selected by pattern:

```php
'my-plugin' => [
    'type' => 'plugin',
    'source' => [
        'kind' => 'github-release',
        'repo' => 'vendor/my-plugin',
        'assetPattern' => 'my-plugin-*.zip',
        'versionFrom' => 'name',
        'releaseStrategy' => 'versions',
    ],
],
```

Private release asset:

```php
'private-plugin' => [
    'type' => 'plugin',
    'source' => [
        'kind' => 'github-release',
        'repo' => 'vendor/private-plugin',
        'asset' => 'private-plugin.zip',
        'versionFrom' => 'tag_name',
        'releaseStrategy' => 'versions',
    ],
],
```

GitHub release sources use `GITHUB_TOKEN` automatically when it is set in the server environment:

```bash
export GITHUB_TOKEN=github_pat_...
```

Use `tokenEnv` only when the token lives in a differently named environment variable.

Release list metadata is cached in `storage/cache/github-release-list` by default. Single release metadata is cached in `storage/cache/github-release-metadata`. Downloaded assets are cached in `storage/cache/github-assets` by slug, version, asset name, and asset identity. GitHub ZIP assets are written to temporary files first, validated as ZIP archives, and then renamed into place.

Version tags such as `v1.2.3` and `1.2.3` normalize to the same package version.

## Version Selection

Package identity is always the slug. Versions are available artifacts for that slug.

The metadata endpoint chooses a version using:

- `installed_version`: if present, select the highest available version greater than the installed version.
- `channel`: defaults to `stable`.
- `wp_version`: optional query value. If omitted, the server attempts to read the WordPress version from the request `User-Agent`.
- `php_version`: optional query value for filtering releases by `requires_php`.
- `version_compare()`: used for sorting WordPress/PHP-style versions.
- compatibility metadata: candidates with `requires` or `requires_php` greater than the client environment are skipped when that environment is known.

Examples:

```text
/metadata/my-plugin?installed_version=1.2.0
/metadata/my-plugin?installed_version=1.2.0&channel=beta
/metadata/my-plugin?installed_version=1.2.0&wp_version=6.5&php_version=8.1
```

If no newer compatible version is available, the server returns a clean no-update response without `download_url`:

```json
{
    "slug": "my-plugin",
    "update_available": false,
    "version": "1.3.0",
    "installed_version": "1.3.0",
    "channel": "stable"
}
```

Stable excludes prerelease versions by default. Channels can be configured per package:

```php
'channels' => [
    'stable' => [
        'versionPattern' => '/^\d+(?:\.\d+){0,2}$/',
        'includePrereleases' => false,
    ],
    'beta' => [
        'versionPattern' => '/^\d+\.\d+\.\d+-(alpha|beta|rc)(?:\.\d+)?$/',
        'includePrereleases' => true,
    ],
],
```

Supported prerelease forms include `1.2.3-alpha`, `1.2.3-alpha.1`, `1.2.3-beta`, `1.2.3-beta.2`, `1.2.3-rc`, and `1.2.3-rc.3`.

## Metadata Overrides

Each package or version can override extracted metadata:

```php
'metadata' => [
    'requires' => '6.3',
    'tested' => '6.8',
    'requires_php' => '8.1',
    'homepage' => 'https://example.com',
    'author' => 'Webshore',
    'sections' => [
        'changelog' => '<p>Fixed update checks.</p>',
    ],
],
```

## Assets

Package icons and banners can be placed in:

```text
public/package-assets/icons/<slug>-128x128.png
public/package-assets/icons/<slug>-256x256.png
public/package-assets/icons/<slug>.svg
public/package-assets/banners/<slug>-772x250.png
public/package-assets/banners/<slug>-1544x500.png
```

The server includes matching asset URLs in metadata responses.

## Signed Downloads

Enable signed download URLs by configuring `config/server.php` and the corresponding environment variables. You can toggle signing with the `WP_UPDATE_SERVER_SIGN_DOWNLOADS` env var and provide the secret via `WP_UPDATE_SERVER_SECRET`.

Example environment configuration:

```bash
export WP_UPDATE_SERVER_SIGN_DOWNLOADS=true
export WP_UPDATE_SERVER_SECRET='long-random-secret'
export WP_UPDATE_SERVER_BASE_URL='https://updates.example.com/'
```

The `downloadSignatureTtl` setting in `config/server.php` controls the signature lifetime (seconds). Signed URLs include `slug`, `version`, `expires`, and `signature` query parameters. The server validates the HMAC before streaming a ZIP, and a signature for one version cannot download another version.

If `signDownloads` is enabled without a configured secret, the server fails closed during startup/config validation instead of serving unsigned downloads.

## Authorization

The default authorization provider allows all metadata and downloads. The architecture includes `AuthorizationProviderInterface`, so license checks, API keys, or custom customer rules can be added later without changing package source code.

Unauthorized metadata responses can omit `download_url` or block metadata entirely depending on the provider implementation.

## Rate Limiting

Downloads use a simple IP-based throttle by default:

```php
'server' => [
    'downloadLimit' => 60,
    'downloadWindowSeconds' => 3600,
],
```

The limiter is replaceable through `RateLimiterInterface`.

When the server runs behind a reverse proxy, forwarded client IP headers are ignored unless the proxy address is explicitly trusted:

```php
'server' => [
    'trustedProxies' => ['127.0.0.1', '10.0.0.0/24'],
    'trustedProxyHeaders' => ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP'],
],
```

Use `WP_UPDATE_SERVER_TRUSTED_PROXIES` and `WP_UPDATE_SERVER_TRUSTED_PROXY_HEADERS` as comma-separated environment variables if you use the default `config/server.php`.

## Logging

Runtime events are written as newline-delimited JSON in `storage/logs/` by default. Logs rotate by UTC date and size:

```text
storage/logs/update-server-2026-05-21.log
storage/logs/update-server-2026-05-21.1.log
```

The default maximum log file size is 10 MB. Override it with `logMaxBytes` or `WP_UPDATE_SERVER_LOG_MAX_BYTES`.

## CLI

```bash
vendor/bin/wpus config validate
vendor/bin/wpus package list
vendor/bin/wpus package get my-plugin
vendor/bin/wpus package get my-plugin --version=1.3.0
vendor/bin/wpus package get my-plugin --channel=beta
vendor/bin/wpus cache warm
vendor/bin/wpus cache status
vendor/bin/wpus cache flush
vendor/bin/wpus cache flush github-assets
vendor/bin/wpus package validate
vendor/bin/wpus package validate my-plugin
vendor/bin/wpus package validate my-plugin --version=1.3.0
```

Use a custom aggregate config path:

```bash
vendor/bin/wpus config validate --config=/path/to/custom-config.php
```

## Plugin Update Checker Integration

```php
require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
    'https://updates.example.com/metadata/my-plugin',
    __FILE__,
    'my-plugin'
);
```

## Theme Integration

Plugin Update Checker also supports themes:

```php
require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
    'https://updates.example.com/metadata/pro-theme',
    get_stylesheet_directory() . '/style.css',
    'pro-theme'
);
```

## Deployment Notes

Apache should point the document root at `public/`. If serving from the project root is unavoidable, keep the `.htaccess` protections from `stubs/config/`, `stubs/storage/`, and other private directories.

Nginx should route requests to `public/index.php` and deny direct access to:

```text
/storage
/config
```

## Security Notes

- Never commit `WP_UPDATE_SERVER_SECRET` or `GITHUB_TOKEN`.
- Prefer signed download URLs for private or paid packages.
- Keep `storage/cache/`, `storage/logs/`, and raw `storage/packages/` non-public.
- GitHub private assets are downloaded server-side and cached locally.
- Configure `trustedProxies` before relying on `X-Forwarded-For`, `X-Real-IP`, or CDN client IP headers.
- Validate package ZIPs before publishing.
- Tune download rate limits for your traffic.

## Development Checks

Run:

```bash
composer lint
composer lint:fix
composer analyse
composer test
composer check
```

`composer lint` runs PHPCS with the project PSR-12 ruleset. `composer analyse` runs PHPStan. `composer check` runs linting, static analysis, and tests in the same sequence used by CI.

The current test suite covers config loading and validation, download signing, trusted proxy IP handling, log rotation, plugin/theme ZIP validation, metadata extraction, filesystem version config, filesystem directory scanning, GitHub release version discovery and asset caching, prerelease channel selection, versioned download URLs, and the path-based metadata endpoint.
