<?php

declare(strict_types=1);

use Webshr\WpUpdateServer\Cache\FilesystemCache;
use Webshr\WpUpdateServer\Config\ConfigLoader;
use Webshr\WpUpdateServer\Config\ConfigValidator;
use Webshr\WpUpdateServer\Config\PackageDefinition;
use Webshr\WpUpdateServer\Config\ServerConfig;
use Webshr\WpUpdateServer\Http\HttpClientInterface;
use Webshr\WpUpdateServer\Http\Request;
use Webshr\WpUpdateServer\Http\StreamHttpClient;
use Webshr\WpUpdateServer\Logging\JsonFileLogger;
use Webshr\WpUpdateServer\Package\PackageInspector;
use Webshr\WpUpdateServer\Package\VersionSelector;
use Webshr\WpUpdateServer\Security\DownloadSigner;
use Webshr\WpUpdateServer\Server\ServerFactory;
use Webshr\WpUpdateServer\Source\FilesystemPackageSource;
use Webshr\WpUpdateServer\Source\GitHubReleasePackageSource;
use Webshr\WpUpdateServer\Source\PackageSourceResolver;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$failures = 0;

function test ( string $name, callable $fn ) : void
{
    global $failures;
    try {
        $fn();
        echo "[PASS] {$name}\n";
    }
    catch ( Throwable $exception ) {
        $failures++;
        echo "[FAIL] {$name}: {$exception->getMessage()}\n";
    }
}

function assert_true ( bool $value, string $message = 'Expected true' ) : void
{
    if ( ! $value ) {
        throw new RuntimeException( $message );
    }
}

function assert_same ( mixed $expected, mixed $actual, string $message = '' ) : void
{
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message ?: sprintf( 'Expected %s, got %s', var_export( $expected, true ), var_export( $actual, true ) ) );
    }
}

function temp_root () : string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpup-' . bin2hex( random_bytes( 6 ) );
    mkdir( $dir, 0775, true );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'config' );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'cache' );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'logs' );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'packages' );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'package-assets' . DIRECTORY_SEPARATOR . 'banners', 0775, true );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'package-assets' . DIRECTORY_SEPARATOR . 'icons', 0775, true );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'package-assets' . DIRECTORY_SEPARATOR . 'banners', 0775, true );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'package-assets' . DIRECTORY_SEPARATOR . 'icons', 0775, true );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache', 0775, true );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs', 0775, true );
    mkdir( $dir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'packages', 0775, true );

    return $dir;
}

function write_server_config ( string $root, string $server = "['baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs']" ) : void
{
    file_put_contents( $root . '/config/server.php', "<?php return {$server};" );
}

function write_packages_config ( string $root, string $packages ) : void
{
    file_put_contents( $root . '/config/packages.php', "<?php return {$packages};" );
}

function write_licenses_config ( string $root, string $licenses ) : void
{
    file_put_contents( $root . '/config/licenses.php', "<?php return {$licenses};" );
}

function write_plugin_zip ( string $path, string $slug = 'my-plugin', string $version = '1.2.3' ) : void
{
    $zip = new ZipArchive();
    $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
    $zip->addFromString( $slug . '/' . $slug . '.php', "<?php\n/*\nPlugin Name: My Plugin\nVersion: {$version}\nPlugin URI: https://example.com/plugin\nAuthor: Webshore\nRequires PHP: 8.2\n*/\n" );
    $zip->addFromString( $slug . '/readme.txt', "=== My Plugin ===\nRequires at least: 6.3\nTested up to: 6.8\nRequires PHP: 8.2\nStable tag: {$version}\n\nShort description.\n\n== Changelog ==\n= {$version} =\nInitial release.\n" );
    $zip->close();
}

function write_flat_plugin_zip ( string $path, string $slug = 'my-plugin', string $version = '1.2.3' ) : void
{
    $zip = new ZipArchive();
    $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
    $zip->addFromString( $slug . '.php', "<?php\n/*\nPlugin Name: My Plugin\nVersion: {$version}\nRequires PHP: 8.2\n*/\n" );
    $zip->addFromString( 'includes/bootstrap.php', "<?php\n" );
    $zip->close();
}

function write_theme_zip ( string $path, string $slug = 'pro-theme', string $version = '2.0.0' ) : void
{
    $zip = new ZipArchive();
    $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
    $zip->addFromString( $slug . '/style.css', "/*\nTheme Name: Pro Theme\nVersion: {$version}\nTheme URI: https://example.com/theme\nAuthor: Webshore\nRequires PHP: 8.2\n*/\n" );
    $zip->close();
}

test( 'loads PHP config and registry', function () : void {
    $root = temp_root();
    write_server_config( $root, "['baseUrl' => 'https://updates.example.com']" );
    write_packages_config( $root, "['my-plugin' => ['type' => 'plugin']]" );

    $config = ( new ConfigLoader( $root ) )->load();

    assert_same( 'https://updates.example.com/', $config->server->baseUrl );
    assert_true( $config->packages->has( 'my-plugin' ) );
} );

test( 'conventional config requires server config and allows missing packages and licenses', function () : void {
    $root = temp_root();

    try {
        ( new ConfigLoader( $root ) )->load();
    }
    catch ( RuntimeException $exception ) {
        assert_true( str_contains( $exception->getMessage(), 'config/server.php' ) );
        write_server_config( $root, "['baseUrl' => 'https://updates.example.com']" );

        $config = ( new ConfigLoader( $root ) )->load();
        assert_same( [], $config->packages->all() );
        assert_same( [], $config->licenses );

        return;
    }

    throw new RuntimeException( 'Expected missing server config to throw.' );
} );

test( 'explicit config path loads aggregate config override', function () : void {
    $root = temp_root();
    file_put_contents( $root . '/custom-config.php', "<?php return ['server' => ['baseUrl' => 'https://custom.example.com'], 'packages' => ['custom-plugin' => ['type' => 'plugin']], 'licenses' => ['license_1' => ['key' => 'TEST']]];" );

    $config = ( new ConfigLoader( $root ) )->load( $root . '/custom-config.php' );

    assert_same( 'https://custom.example.com/', $config->server->baseUrl );
    assert_true( $config->packages->has( 'custom-plugin' ) );
    assert_same( 'TEST', $config->licenses['license_1']['key'] );
} );

test( 'root aggregate config overrides conventional config', function () : void {
    $root = temp_root();
    write_server_config( $root, "['baseUrl' => 'https://conventional.example.com']" );
    write_packages_config( $root, "['conventional-plugin' => ['type' => 'plugin']]" );
    file_put_contents( $root . '/update-server.php', "<?php return ['server' => ['baseUrl' => 'https://aggregate.example.com'], 'packages' => ['aggregate-plugin' => ['type' => 'plugin']]];" );

    $config = ( new ConfigLoader( $root ) )->load();

    assert_same( 'https://aggregate.example.com/', $config->server->baseUrl );
    assert_true( $config->packages->has( 'aggregate-plugin' ) );
    assert_true( ! $config->packages->has( 'conventional-plugin' ) );
} );

test( 'server config resolves storage and public asset paths', function () : void {
    $root   = temp_root();
    $server = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'storageDir' => 'var' ] ], $root );

    assert_true( str_ends_with( str_replace( '\\', '/', $server->storageDir ), '/var' ) );
    assert_true( str_ends_with( str_replace( '\\', '/', $server->cacheDir ), '/var/cache' ) );
    assert_true( str_ends_with( str_replace( '\\', '/', $server->packageDir ), '/var/packages' ) );
    assert_true( str_ends_with( str_replace( '\\', '/', $server->packageAssetDir ), '/public/package-assets' ) );
    assert_true( str_ends_with( str_replace( '\\', '/', $server->logDir ), '/var/logs' ) );
} );

test( 'signs and validates download params', function () : void {
    $signer = new DownloadSigner( 'secret', 300 );
    $params = $signer->sign( [ 'slug' => 'my-plugin', 'version' => '1.2.3' ] );

    assert_true( isset( $params['signature'] ) );
    assert_true( $signer->validate( $params, [ 'slug' => 'my-plugin', 'version' => '1.2.3' ] ) );
    $params['slug'] = 'other';
    assert_true( ! $signer->validate( $params, [ 'slug' => 'my-plugin', 'version' => '1.2.3' ] ), 'Tampered signature should fail' );
} );

test( 'filesystem cache writes atomically and cleans temporary files', function () : void {
    $root  = temp_root();
    $cache = new FilesystemCache( $root . '/cache' );

    $cache->set( 'runtime', 'package', [ 'version' => '1.0.0' ], 3600 );
    $cache->set( 'runtime', 'package', [ 'version' => '1.0.1' ], 3600 );

    assert_same( [ 'version' => '1.0.1' ], $cache->get( 'runtime', 'package' ) );
    assert_same( [], glob( $root . '/cache/runtime/*.tmp' ) ?: [] );
    assert_same( 1, count( glob( $root . '/cache/runtime/*.json' ) ?: [] ) );
} );

test( 'server factory refuses signed downloads without a secret', function () : void {
    $root = temp_root();
    write_server_config( $root, "['baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs', 'signDownloads' => true]" );

    try {
        ( new ServerFactory( $root ) )->server();
    }
    catch ( RuntimeException $exception ) {
        assert_true( str_contains( $exception->getMessage(), 'Download signing is enabled' ) );
        return;
    }

    throw new RuntimeException( 'Expected signing configuration to fail closed.' );
} );

test( 'config validator reports actionable signing errors', function () : void {
    $root = temp_root();
    write_server_config( $root, "['baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs', 'signDownloads' => true]" );

    $config = ( new ConfigLoader( $root ) )->load();
    $result = ( new ConfigValidator() )->validate( $config );

    assert_true( ! $result->valid() );
    assert_true( str_contains( implode( "\n", $result->errors ), 'Download signing is enabled' ) );
} );

test( 'trusted proxy headers are ignored unless the proxy is trusted', function () : void {
    $server = $_SERVER;
    $get    = $_GET;
    $post   = $_POST;

    $_SERVER = [
        'REMOTE_ADDR'          => '10.0.0.8',
        'REQUEST_METHOD'       => 'GET',
        'REQUEST_URI'          => '/metadata/my-plugin',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 10.0.0.8',
    ];
    $_GET    = [];
    $_POST   = [];

    try {
        assert_same( '10.0.0.8', Request::fromGlobals()->clientIp );
        assert_same( '198.51.100.10', Request::fromGlobals( [ '10.0.0.0/24' ], [ 'X-Forwarded-For' ] )->clientIp );
    }
    finally {
        $_SERVER = $server;
        $_GET    = $get;
        $_POST   = $post;
    }
} );

test( 'json logger rotates by date and size', function () : void {
    $root   = temp_root();
    $logger = new JsonFileLogger( $root . '/logs', 1 );

    $logger->log( 'first' );
    $logger->log( 'second' );

    $files = glob( $root . '/logs/update-server-*.log' ) ?: [];
    sort( $files );

    assert_same( 2, count( $files ) );
    assert_true( count( array_filter( $files, static fn ( string $path ) : bool => str_contains( $path, '.1.log' ) ) ) === 1 );
} );

test( 'stream HTTP client summarizes HTML error bodies', function () : void {
    $method  = new ReflectionMethod( StreamHttpClient::class, 'errorMessage' );
    $message = $method->invoke( new StreamHttpClient(), '<!DOCTYPE html><html><body><h1>Gateway timeout</h1><p>Please retry later.</p></body></html>' );

    assert_same( 'Gateway timeout Please retry later.', $message );
} );

test( 'inspects plugin metadata and validates archive shape', function () : void {
    $root    = temp_root();
    $archive = $root . '/packages/my-plugin.zip';
    write_plugin_zip( $archive );

    $inspector = new PackageInspector();
    $metadata  = $inspector->inspect( $archive, 'my-plugin', 'plugin' );
    $result    = $inspector->validate( $archive, 'my-plugin', 'plugin' );

    assert_same( 'My Plugin', $metadata['name'] );
    assert_same( '1.2.3', $metadata['version'] );
    assert_true( $result->valid, implode( ', ', $result->errors ) );
} );

test( 'inspects theme metadata and validates style.css', function () : void {
    $root    = temp_root();
    $archive = $root . '/packages/pro-theme.zip';
    write_theme_zip( $archive );

    $inspector = new PackageInspector();
    $metadata  = $inspector->inspect( $archive, 'pro-theme', 'theme' );
    $result    = $inspector->validate( $archive, 'pro-theme', 'theme' );

    assert_same( 'Pro Theme', $metadata['name'] );
    assert_same( '2.0.0', $metadata['version'] );
    assert_true( $result->valid, implode( ', ', $result->errors ) );
} );

test( 'metadata endpoint returns fresh path-based JSON with download URL', function () : void {
    $root = temp_root();
    write_plugin_zip( $root . '/packages/my-plugin.zip' );
    write_server_config( $root );
    write_packages_config( $root, "['my-plugin' => ['type' => 'plugin', 'source' => ['kind' => 'filesystem', 'path' => 'packages/my-plugin.zip'], 'metadata' => ['tested' => '6.9']]]" );

    $server   = ( new ServerFactory( $root ) )->server();
    $response = $server->handle( new Request( [], [ 'User-Agent' => 'WordPress/6.8; https://site.example' ], 'GET', '127.0.0.1', '/metadata/my-plugin' ) );
    $payload  = json_decode( $response->body, true );

    assert_same( 200, $response->status );
    assert_same( 'my-plugin', $payload['slug'] );
    assert_same( '1.2.3', $payload['version'] );
    assert_same( '6.9', $payload['tested'] );
    assert_true( str_contains( $payload['download_url'], '/download/my-plugin/1.2.3' ) );
} );

test( 'metadata endpoint returns package assets from configured public asset directory', function () : void {
    $root = temp_root();
    write_plugin_zip( $root . '/storage/packages/my-plugin.zip' );
    file_put_contents( $root . '/public/package-assets/banners/my-plugin-772x250.png', 'banner' );
    file_put_contents( $root . '/public/package-assets/icons/my-plugin.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>' );
    write_server_config( $root, "['baseUrl' => 'https://updates.example.com/', 'packageDir' => 'storage/packages', 'packageAssetDir' => 'public/package-assets']" );
    write_packages_config( $root, "['my-plugin' => ['type' => 'plugin']]" );

    $server   = ( new ServerFactory( $root ) )->server();
    $response = $server->handle( new Request( [ 'installed_version' => '1.0.0' ], [], 'GET', '127.0.0.1', '/metadata/my-plugin' ) );
    $payload  = json_decode( $response->body, true );

    assert_same( 200, $response->status );
    assert_same( 'https://updates.example.com/package-assets/banners/my-plugin-772x250.png', $payload['banners']['low'] );
    assert_same( 'https://updates.example.com/package-assets/icons/my-plugin.svg', $payload['icons']['svg'] );
} );

test( 'filesystem package supports multiple configured versions and installed version selection', function () : void {
    $root = temp_root();
    mkdir( $root . '/packages/my-plugin/1.2.0', 0775, true );
    mkdir( $root . '/packages/my-plugin/1.3.0', 0775, true );
    write_plugin_zip( $root . '/packages/my-plugin/1.2.0/my-plugin.zip', 'my-plugin', '1.2.0' );
    write_plugin_zip( $root . '/packages/my-plugin/1.3.0/my-plugin.zip', 'my-plugin', '1.3.0' );
    write_server_config( $root );
    write_packages_config( $root, "['my-plugin' => ['type' => 'plugin', 'versions' => ['1.2.0' => ['source' => ['kind' => 'filesystem', 'path' => 'packages/my-plugin/1.2.0/my-plugin.zip']], '1.3.0' => ['source' => ['kind' => 'filesystem', 'path' => 'packages/my-plugin/1.3.0/my-plugin.zip'], 'metadata' => ['tested' => '6.9']]]]]" );

    $server   = ( new ServerFactory( $root ) )->server();
    $response = $server->handle( new Request( [ 'installed_version' => '1.2.0' ], [], 'GET', '127.0.0.1', '/metadata/my-plugin' ) );
    $payload  = json_decode( $response->body, true );

    assert_same( 200, $response->status );
    assert_same( '1.3.0', $payload['version'] );
    assert_same( '6.9', $payload['tested'] );
    assert_true( str_contains( $payload['download_url'], '/download/my-plugin/1.3.0' ) );

    $download = $server->handle( new Request( [], [], 'GET', '127.0.0.1', '/download/my-plugin/1.3.0' ) );
    assert_same( 200, $download->status );
    assert_true( str_ends_with( str_replace( '\\', '/', (string) $download->filePath ), 'packages/my-plugin/1.3.0/my-plugin.zip' ) );
} );

test( 'up-to-date clients receive clean no-update JSON', function () : void {
    $root = temp_root();
    mkdir( $root . '/packages/my-plugin/1.3.0', 0775, true );
    write_plugin_zip( $root . '/packages/my-plugin/1.3.0/my-plugin.zip', 'my-plugin', '1.3.0' );
    write_server_config( $root );
    write_packages_config( $root, "['my-plugin' => ['type' => 'plugin', 'versions' => ['1.3.0' => ['source' => ['kind' => 'filesystem', 'path' => 'packages/my-plugin/1.3.0/my-plugin.zip']]]]]" );

    $server   = ( new ServerFactory( $root ) )->server();
    $response = $server->handle( new Request( [ 'installed_version' => '1.3.0' ], [], 'GET', '127.0.0.1', '/metadata/my-plugin' ) );
    $payload  = json_decode( $response->body, true );

    assert_same( 200, $response->status );
    assert_same( false, $payload['update_available'] );
    assert_same( '1.3.0', $payload['version'] );
    assert_true( ! isset( $payload['download_url'] ), 'No-update response must not include download_url' );
} );

test( 'licensed packages only expose downloads to activated sites', function () : void {
    $root = temp_root();
    write_plugin_zip( $root . '/packages/my-plugin.zip', 'my-plugin', '1.2.3' );
    write_server_config( $root );
    write_licenses_config( $root, "['license_1' => ['key' => 'TEST-LICENSE', 'status' => 'active', 'packages' => ['my-plugin'], 'activationLimit' => 1]]" );
    write_packages_config( $root, "['my-plugin' => ['type' => 'plugin', 'license' => ['required' => true], 'source' => ['kind' => 'filesystem', 'path' => 'packages/my-plugin.zip']]]" );

    $server          = ( new ServerFactory( $root ) )->server();
    $metadata        = $server->handle( new Request( [ 'installed_version' => '1.0.0', 'site_url' => 'https://site.example' ], [], 'GET', '127.0.0.1', '/metadata/my-plugin' ) );
    $metadataPayload = json_decode( $metadata->body, true );

    assert_same( 200, $metadata->status );
    assert_same( true, $metadataPayload['license_required'] );
    assert_true( ! isset( $metadataPayload['download_url'] ), 'Unlicensed metadata must not include download_url' );

    $blockedDownload = $server->handle( new Request( [], [], 'GET', '127.0.0.1', '/download/my-plugin/1.2.3' ) );
    assert_same( 403, $blockedDownload->status );

    $activation        = $server->handle( new Request( [], [], 'POST', '127.0.0.1', '/license/my-plugin/activate', [
        'license_key' => 'TEST-LICENSE',
        'site_url'    => 'https://site.example',
    ] ) );
    $activationPayload = json_decode( $activation->body, true );

    assert_same( 200, $activation->status );
    assert_same( true, $activationPayload['success'] );
    assert_true( isset( $activationPayload['activation_id'] ) );

    $licensedMetadata = $server->handle( new Request( [
        'installed_version' => '1.0.0',
        'license_key'       => 'TEST-LICENSE',
        'activation_id'     => $activationPayload['activation_id'],
        'site_url'          => 'https://site.example',
    ], [], 'GET', '127.0.0.1', '/metadata/my-plugin' ) );
    $licensedPayload  = json_decode( $licensedMetadata->body, true );

    assert_same( 200, $licensedMetadata->status );
    assert_true( ! str_contains( $licensedPayload['download_url'], 'license_key=TEST-LICENSE' ) );
    assert_true( str_contains( $licensedPayload['download_url'], 'token=' ) );

    parse_str( (string) parse_url( $licensedPayload['download_url'], PHP_URL_QUERY ), $downloadParams );
    $download = $server->handle( new Request( $downloadParams, [], 'GET', '127.0.0.1', '/download/my-plugin/1.2.3' ) );
    assert_same( 200, $download->status );

    $check        = $server->handle( new Request( [
        'license_key'   => 'TEST-LICENSE',
        'activation_id' => $activationPayload['activation_id'],
        'site_url'      => 'https://site.example',
    ], [], 'GET', '127.0.0.1', '/license/my-plugin/check' ) );
    $checkPayload = json_decode( $check->body, true );

    assert_same( 200, $check->status );
    assert_same( true, $checkPayload['active'] );
    assert_same( true, $checkPayload['site_activated'] );
} );

test( 'cache clear requires signed requests and validates namespaces', function () : void {
    $root = temp_root();
    write_plugin_zip( $root . '/packages/my-plugin.zip', 'my-plugin', '1.2.3' );
    write_server_config( $root, "['baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs', 'signDownloads' => true, 'downloadSecret' => 'secret']" );
    write_packages_config( $root, "['my-plugin' => ['type' => 'plugin', 'source' => ['kind' => 'filesystem', 'path' => 'packages/my-plugin.zip']]]" );

    $server   = ( new ServerFactory( $root ) )->server();
    $unsigned = $server->handle( new Request( [], [], 'POST', '127.0.0.1', '/cache/clear' ) );
    assert_same( 403, $unsigned->status );

    $signer       = new DownloadSigner( 'secret', 300 );
    $badNamespace = $server->handle( new Request( $signer->sign( [ 'namespace' => '../logs' ] ), [], 'POST', '127.0.0.1', '/cache/clear' ) );
    assert_same( 400, $badNamespace->status );

    $good = $server->handle( new Request( $signer->sign( [ 'namespace' => 'github-assets' ] ), [], 'POST', '127.0.0.1', '/cache/clear' ) );
    assert_same( 200, $good->status );
} );

test( 'license endpoints are rate limited', function () : void {
    $root = temp_root();
    write_plugin_zip( $root . '/packages/my-plugin.zip', 'my-plugin', '1.2.3' );
    write_server_config( $root, "['baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs', 'downloadLimit' => 1, 'downloadWindowSeconds' => 3600]" );
    write_licenses_config( $root, "['license_1' => ['key' => 'TEST-LICENSE', 'status' => 'active', 'packages' => ['my-plugin']]]" );
    write_packages_config( $root, "['my-plugin' => ['type' => 'plugin', 'license' => ['required' => true], 'source' => ['kind' => 'filesystem', 'path' => 'packages/my-plugin.zip']]]" );

    $server = ( new ServerFactory( $root ) )->server();
    $first  = $server->handle( new Request( [], [], 'POST', '127.0.0.1', '/license/my-plugin/activate', [
        'license_key' => 'bad',
        'site_url'    => 'https://site.example',
    ] ) );
    $second = $server->handle( new Request( [], [], 'POST', '127.0.0.1', '/license/my-plugin/activate', [
        'license_key' => 'bad',
        'site_url'    => 'https://site.example',
    ] ) );

    assert_same( 404, $first->status );
    assert_same( 429, $second->status );
} );

test( 'version selection skips incompatible WordPress and PHP requirements', function () : void {
    $root = temp_root();
    mkdir( $root . '/packages/my-plugin/1.2.0', 0775, true );
    mkdir( $root . '/packages/my-plugin/1.3.0', 0775, true );
    write_plugin_zip( $root . '/packages/my-plugin/1.2.0/my-plugin.zip', 'my-plugin', '1.2.0' );
    write_plugin_zip( $root . '/packages/my-plugin/1.3.0/my-plugin.zip', 'my-plugin', '1.3.0' );
    write_server_config( $root );
    write_packages_config( $root, "['my-plugin' => ['type' => 'plugin', 'versions' => ['1.2.0' => ['source' => ['kind' => 'filesystem', 'path' => 'packages/my-plugin/1.2.0/my-plugin.zip'], 'metadata' => ['requires' => '6.0', 'requires_php' => '8.0']], '1.3.0' => ['source' => ['kind' => 'filesystem', 'path' => 'packages/my-plugin/1.3.0/my-plugin.zip'], 'metadata' => ['requires' => '6.8', 'requires_php' => '8.3']]]]]" );

    $server   = ( new ServerFactory( $root ) )->server();
    $response = $server->handle( new Request( [ 'installed_version' => '1.1.0', 'php_version' => '8.1' ], [ 'User-Agent' => 'WordPress/6.5; https://site.example' ], 'GET', '127.0.0.1', '/metadata/my-plugin' ) );
    $payload  = json_decode( $response->body, true );

    assert_same( 200, $response->status );
    assert_same( '1.2.0', $payload['version'] );
} );

test( 'filesystem directory scan detects versions from archive names', function () : void {
    $root = temp_root();
    mkdir( $root . '/packages/my-plugin', 0775, true );
    write_plugin_zip( $root . '/packages/my-plugin/my-plugin-1.0.0.zip', 'my-plugin', '1.0.0' );
    write_plugin_zip( $root . '/packages/my-plugin/my-plugin-1.1.0.zip', 'my-plugin', '1.1.0' );

    $server   = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs' ] ], $root );
    $package  = PackageDefinition::fromArray( 'my-plugin', [
        'type'   => 'plugin',
        'source' => [
            'kind'           => 'filesystem',
            'path'           => 'packages/my-plugin',
            'versionPattern' => '/^my-plugin-(?<version>.+)\.zip$/',
        ],
    ] );
    $source   = new FilesystemPackageSource( $server, new PackageInspector() );
    $versions = array_map( static fn ( $version ) : string => $version->version, $source->listVersions( $package )->all() );

    assert_same( [ '1.1.0', '1.0.0' ], $versions );
} );

test( 'stable channel excludes prereleases and beta channel can select them', function () : void {
    $root = temp_root();
    mkdir( $root . '/packages/my-plugin', 0775, true );
    write_plugin_zip( $root . '/packages/my-plugin/my-plugin-1.2.0.zip', 'my-plugin', '1.2.0' );
    write_plugin_zip( $root . '/packages/my-plugin/my-plugin-1.3.0-beta.1.zip', 'my-plugin', '1.3.0-beta.1' );

    $server   = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs' ] ], $root );
    $package  = PackageDefinition::fromArray( 'my-plugin', [
        'type'   => 'plugin',
        'source' => [
            'kind'           => 'filesystem',
            'path'           => 'packages/my-plugin',
            'versionPattern' => '/^my-plugin-(?<version>.+)\.zip$/',
        ],
    ] );
    $source   = new FilesystemPackageSource( $server, new PackageInspector() );
    $selector = new VersionSelector();

    assert_same( '1.2.0', $selector->select( $package, $source->listVersions( $package ), null, 'stable' )->version );
    assert_same( '1.3.0-beta.1', $selector->select( $package, $source->listVersions( $package ), '1.2.0', 'beta' )->version );
} );

test( 'versioned download URL signature cannot be reused for another version', function () : void {
    $signer = new DownloadSigner( 'secret', 300 );
    $params = $signer->sign( [ 'slug' => 'my-plugin', 'version' => '1.2.0' ] );

    assert_true( $signer->validate( $params, [ 'slug' => 'my-plugin', 'version' => '1.2.0' ] ) );
    assert_true( ! $signer->validate( $params, [ 'slug' => 'my-plugin', 'version' => '1.3.0' ] ) );
} );

test( 'theme version archive selection works', function () : void {
    $root = temp_root();
    mkdir( $root . '/packages/pro-theme/2.0.0', 0775, true );
    mkdir( $root . '/packages/pro-theme/2.1.0', 0775, true );
    write_theme_zip( $root . '/packages/pro-theme/2.0.0/pro-theme.zip', 'pro-theme', '2.0.0' );
    write_theme_zip( $root . '/packages/pro-theme/2.1.0/pro-theme.zip', 'pro-theme', '2.1.0' );
    write_server_config( $root );
    write_packages_config( $root, "['pro-theme' => ['type' => 'theme', 'versions' => ['2.0.0' => ['source' => ['kind' => 'filesystem', 'path' => 'packages/pro-theme/2.0.0/pro-theme.zip']], '2.1.0' => ['source' => ['kind' => 'filesystem', 'path' => 'packages/pro-theme/2.1.0/pro-theme.zip']]]]]" );

    $server   = ( new ServerFactory( $root ) )->server();
    $response = $server->handle( new Request( [ 'installed_version' => '2.0.0' ], [], 'GET', '127.0.0.1', '/metadata/pro-theme' ) );
    $payload  = json_decode( $response->body, true );

    assert_same( 200, $response->status );
    assert_same( '2.1.0', $payload['version'] );
    assert_true( str_contains( $payload['download_url'], '/download/pro-theme/2.1.0' ) );
} );

test( 'GitHub source lists stable and prerelease versions and proxies cached assets', function () : void {
    $root     = temp_root();
    $assetZip = $root . '/asset.zip';
    write_plugin_zip( $assetZip, 'my-plugin', '3.4.5' );
    $assetBytes = (string) file_get_contents( $assetZip );

    $client = new class ($assetBytes) implements HttpClientInterface {
        public function __construct ( private readonly string $assetBytes ) {}

        public function get ( string $url, array $headers = [] ) : string
        {
            if ( str_contains( $url, '/releases?' ) ) {
                return json_encode( [
                    [
                        'tag_name'     => 'v3.4.5',
                        'name'         => 'Release 3.4.5',
                        'draft'        => false,
                        'prerelease'   => false,
                        'published_at' => '2026-05-20T00:00:00Z',
                        'assets'       => [
                            [
                                'id'         => 123,
                                'name'       => 'my-plugin.zip',
                                'url'        => 'https://api.github.com/repos/vendor/my-plugin/releases/assets/123',
                                'updated_at' => '2026-05-20T00:00:00Z',
                            ],
                        ],
                    ],
                    [
                        'tag_name'     => 'v3.5.0-beta.1',
                        'name'         => 'Release 3.5.0 beta 1',
                        'draft'        => false,
                        'prerelease'   => false,
                        'published_at' => '2026-05-21T00:00:00Z',
                        'assets'       => [
                            [
                                'id'         => 124,
                                'name'       => 'my-plugin.zip',
                                'url'        => 'https://api.github.com/repos/vendor/my-plugin/releases/assets/124',
                                'updated_at' => '2026-05-21T00:00:00Z',
                            ],
                        ],
                    ],
                    [
                        'tag_name'   => 'draft-4.0.0',
                        'draft'      => true,
                        'prerelease' => false,
                        'assets'     => [
                            [
                                'id'   => 125,
                                'name' => 'my-plugin.zip',
                                'url'  => 'https://api.github.com/repos/vendor/my-plugin/releases/assets/125',
                            ],
                        ],
                    ],
                    [
                        'tag_name'   => 'not-a-package-release',
                        'draft'      => false,
                        'prerelease' => false,
                        'assets'     => [],
                    ],
                ], JSON_THROW_ON_ERROR );
            }

            return $this->assetBytes;
        }
    };

    $server  = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs' ] ], $root );
    $source  = new GitHubReleasePackageSource( $server, new FilesystemCache( $server->cacheDir ), new PackageInspector(), $client );
    $package = PackageDefinition::fromArray( 'my-plugin', [
        'type'   => 'plugin',
        'source' => [
            'kind'            => 'github-release',
            'repo'            => 'vendor/my-plugin',
            'asset'           => 'my-plugin.zip',
            'versionFrom'     => 'tag_name',
            'releaseStrategy' => 'versions',
            'versionPattern'  => '/^\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)(?:\.\d+)?)?$/',
        ],
    ] );

    $versions = $source->listVersions( $package );
    $selector = new VersionSelector();
    assert_same( '3.4.5', $selector->select( $package, $versions, null, 'stable' )->version );
    assert_same( '3.5.0-beta.1', $selector->select( $package, $versions, '3.4.5', 'beta' )->version );

    $resolved = $source->resolveVersion( $package, '3.4.5' );
    $metadata = $resolved->metadata->toArray();

    assert_same( '3.4.5', $metadata['version'] );
    assert_true( is_file( $resolved->archivePath ) );
    assert_true( str_contains( str_replace( '\\', '/', $resolved->archivePath ), 'github-assets' ) );
} );

test( 'GitHub source uses GITHUB_TOKEN by default', function () : void {
    $root          = temp_root();
    $previousToken = getenv( 'GITHUB_TOKEN' );
    putenv( 'GITHUB_TOKEN=default-token' );

    $client = new class implements HttpClientInterface {
        /** @var list<string> */
        public array $headers = [];

        public function get ( string $url, array $headers = [] ) : string
        {
            $this->headers = $headers;

            return json_encode( [
                [
                    'tag_name'   => 'v1.0.0',
                    'draft'      => false,
                    'prerelease' => false,
                    'assets'     => [
                        [
                            'id'   => 100,
                            'name' => 'my-plugin.zip',
                            'url'  => 'https://api.github.com/repos/vendor/my-plugin/releases/assets/100',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR );
        }
    };

    try {
        $server  = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs' ] ], $root );
        $source  = new GitHubReleasePackageSource( $server, new FilesystemCache( $server->cacheDir ), new PackageInspector(), $client );
        $package = PackageDefinition::fromArray( 'my-plugin', [
            'type'   => 'plugin',
            'source' => [
                'kind'            => 'github-release',
                'repo'            => 'vendor/my-plugin',
                'asset'           => 'my-plugin.zip',
                'releaseStrategy' => 'versions',
            ],
        ] );

        $source->listVersions( $package );

        assert_true( in_array( 'Authorization: Bearer default-token', $client->headers, true ) );
    }
    finally {
        if ( $previousToken === false ) {
            putenv( 'GITHUB_TOKEN' );
        }
        else {
            putenv( 'GITHUB_TOKEN=' . $previousToken );
        }
    }
} );

test( 'GitHub source can normalize flat release archives before serving them', function () : void {
    $root     = temp_root();
    $assetZip = $root . '/flat-asset.zip';
    write_flat_plugin_zip( $assetZip, 'my-plugin', '2.0.0-beta.1' );
    $assetBytes = (string) file_get_contents( $assetZip );

    $client = new class ($assetBytes) implements HttpClientInterface {
        public function __construct ( private readonly string $assetBytes ) {}

        public function get ( string $url, array $headers = [] ) : string
        {
            if ( str_contains( $url, '/releases?' ) ) {
                return json_encode( [
                    [
                        'tag_name'   => 'v2.0.0-beta.1',
                        'draft'      => false,
                        'prerelease' => false,
                        'assets'     => [
                            [
                                'id'         => 300,
                                'name'       => 'my-plugin.zip',
                                'url'        => 'https://api.github.com/repos/vendor/my-plugin/releases/assets/300',
                                'updated_at' => '2026-05-20T00:00:00Z',
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR );
            }

            return $this->assetBytes;
        }
    };

    $server  = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs' ] ], $root );
    $source  = new GitHubReleasePackageSource( $server, new FilesystemCache( $server->cacheDir ), new PackageInspector(), $client );
    $package = PackageDefinition::fromArray( 'my-plugin', [
        'type'         => 'plugin',
        'normalizeZip' => true,
        'source'       => [
            'kind'            => 'github-release',
            'repo'            => 'vendor/my-plugin',
            'asset'           => 'my-plugin.zip',
            'releaseStrategy' => 'versions',
        ],
    ] );

    $resolved   = $source->resolveVersion( $package, '2.0.0-beta.1' );
    $validation = ( new PackageInspector() )->validate( $resolved->archivePath, 'my-plugin', 'plugin' );

    assert_true( $validation->valid, implode( ', ', $validation->errors ) );
    assert_true( str_contains( str_replace( '\\', '/', $resolved->archivePath ), 'normalized-packages' ) );
} );

test( 'GitHub source rejects error payloads instead of caching them as empty releases', function () : void {
    $root          = temp_root();
    $previousToken = getenv( 'GITHUB_TOKEN' );
    putenv( 'GITHUB_TOKEN' );

    $client = new class implements HttpClientInterface {
        public function get ( string $url, array $headers = [] ) : string
        {
            return json_encode( [
                'message'           => 'Not Found',
                'documentation_url' => 'https://docs.github.com/rest/releases/releases#list-releases',
                'status'            => '404',
            ], JSON_THROW_ON_ERROR );
        }
    };

    $server  = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs' ] ], $root );
    $source  = new GitHubReleasePackageSource( $server, new FilesystemCache( $server->cacheDir ), new PackageInspector(), $client );
    $package = PackageDefinition::fromArray( 'my-plugin', [
        'type'   => 'plugin',
        'source' => [
            'kind'            => 'github-release',
            'repo'            => 'vendor/my-plugin',
            'asset'           => 'my-plugin.zip',
            'releaseStrategy' => 'versions',
        ],
    ] );

    try {
        $source->listVersions( $package );
    }
    catch ( RuntimeException $exception ) {
        assert_true( str_contains( $exception->getMessage(), 'could not find repository "vendor/my-plugin"' ) );
        assert_true( str_contains( $exception->getMessage(), 'source.tokenEnv "GITHUB_TOKEN" is not set' ) );

        if ( $previousToken === false ) {
            putenv( 'GITHUB_TOKEN' );
        }
        else {
            putenv( 'GITHUB_TOKEN=' . $previousToken );
        }

        return;
    }

    if ( $previousToken === false ) {
        putenv( 'GITHUB_TOKEN' );
    }
    else {
        putenv( 'GITHUB_TOKEN=' . $previousToken );
    }

    throw new RuntimeException( 'Expected GitHub error payload to throw.' );
} );

test( 'GitHub source explains private repo 404s when tokenEnv is missing', function () : void {
    $root     = temp_root();
    $tokenEnv = 'WPUS_TEST_MISSING_GITHUB_TOKEN_' . bin2hex( random_bytes( 4 ) );
    putenv( $tokenEnv );

    $client = new class implements HttpClientInterface {
        public function get ( string $url, array $headers = [] ) : string
        {
            return json_encode( [
                'message'           => 'Not Found',
                'documentation_url' => 'https://docs.github.com/rest/releases/releases#list-releases',
                'status'            => '404',
            ], JSON_THROW_ON_ERROR );
        }
    };

    $server  = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs' ] ], $root );
    $source  = new GitHubReleasePackageSource( $server, new FilesystemCache( $server->cacheDir ), new PackageInspector(), $client );
    $package = PackageDefinition::fromArray( 'my-plugin', [
        'type'   => 'plugin',
        'source' => [
            'kind'            => 'github-release',
            'repo'            => 'vendor/my-plugin',
            'asset'           => 'my-plugin.zip',
            'tokenEnv'        => $tokenEnv,
            'releaseStrategy' => 'versions',
        ],
    ] );

    try {
        $source->listVersions( $package );
    }
    catch ( RuntimeException $exception ) {
        assert_true( str_contains( $exception->getMessage(), sprintf( 'source.tokenEnv "%s" is not set', $tokenEnv ) ) );

        return;
    }

    throw new RuntimeException( 'Expected GitHub not found error to explain missing tokenEnv.' );
} );

test( 'GitHub source rejects invalid asset downloads instead of caching them', function () : void {
    $root   = temp_root();
    $client = new class implements HttpClientInterface {
        public function get ( string $url, array $headers = [] ) : string
        {
            if ( str_contains( $url, '/releases?' ) ) {
                return json_encode( [
                    [
                        'tag_name'   => 'v1.2.0',
                        'draft'      => false,
                        'prerelease' => false,
                        'assets'     => [
                            [
                                'id'   => 400,
                                'name' => 'my-plugin.zip',
                                'url'  => 'https://api.github.com/repos/vendor/my-plugin/releases/assets/400',
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR );
            }

            return json_encode( [ 'message' => 'Not Found' ], JSON_THROW_ON_ERROR );
        }
    };

    $server  = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs' ] ], $root );
    $source  = new GitHubReleasePackageSource( $server, new FilesystemCache( $server->cacheDir ), new PackageInspector(), $client );
    $package = PackageDefinition::fromArray( 'my-plugin', [
        'type'   => 'plugin',
        'source' => [
            'kind'            => 'github-release',
            'repo'            => 'vendor/my-plugin',
            'asset'           => 'my-plugin.zip',
            'releaseStrategy' => 'versions',
        ],
    ] );

    try {
        $source->resolveVersion( $package, '1.2.0' );
    }
    catch ( RuntimeException $exception ) {
        assert_true( str_contains( $exception->getMessage(), 'valid ZIP archive' ) );
        assert_same( [], glob( $root . '/cache/github-assets/*.zip' ) ?: [] );
        assert_same( [], glob( $root . '/cache/github-assets/*.tmp' ) ?: [] );

        return;
    }

    throw new RuntimeException( 'Expected invalid asset response to throw.' );
} );

test( 'GitHub version discovery skips matching versions without configured asset', function () : void {
    $root     = temp_root();
    $assetZip = $root . '/asset.zip';
    write_plugin_zip( $assetZip, 'my-plugin', '1.2.0' );
    $assetBytes = (string) file_get_contents( $assetZip );

    $client = new class ($assetBytes) implements HttpClientInterface {
        public function __construct ( private readonly string $assetBytes ) {}

        public function get ( string $url, array $headers = [] ) : string
        {
            if ( str_contains( $url, '/releases?' ) ) {
                return json_encode( [
                    [
                        'tag_name'   => 'v1.3.0',
                        'draft'      => false,
                        'prerelease' => false,
                        'assets'     => [],
                    ],
                    [
                        'tag_name'   => 'v1.2.0',
                        'draft'      => false,
                        'prerelease' => false,
                        'assets'     => [
                            [
                                'id'         => 200,
                                'name'       => 'my-plugin.zip',
                                'url'        => 'https://api.github.com/repos/vendor/my-plugin/releases/assets/200',
                                'updated_at' => '2026-05-20T00:00:00Z',
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR );
            }

            return $this->assetBytes;
        }
    };

    $server  = ServerConfig::fromArray( [ 'server' => [ 'baseUrl' => 'https://updates.example.com/', 'cacheDir' => 'cache', 'packageDir' => 'packages', 'logDir' => 'logs' ] ], $root );
    $source  = new GitHubReleasePackageSource( $server, new FilesystemCache( $server->cacheDir ), new PackageInspector(), $client );
    $package = PackageDefinition::fromArray( 'my-plugin', [
        'type'   => 'plugin',
        'source' => [
            'kind'            => 'github-release',
            'repo'            => 'vendor/my-plugin',
            'asset'           => 'my-plugin.zip',
            'versionFrom'     => 'tag_name',
            'releaseStrategy' => 'versions',
        ],
    ] );

    $versions = array_map( static fn ( $version ) : string => $version->version, $source->listVersions( $package )->all() );
    assert_same( [ '1.2.0' ], $versions );
} );

exit( $failures > 0 ? 1 : 0 );
