<?php

declare(strict_types=1);

use Webshr\WpUpdateServer\Support\Env;

$storageDir = Env::string('WP_UPDATE_SERVER_STORAGE_DIR', 'storage');

return [
    'baseUrl'               => Env::string('WP_UPDATE_SERVER_BASE_URL', 'http://localhost:8000/'),
    'storageDir'            => $storageDir,
    'cacheDir'              => Env::string('WP_UPDATE_SERVER_CACHE_DIR', $storageDir . '/cache'),
    'packageDir'            => Env::string('WP_UPDATE_SERVER_PACKAGE_DIR', $storageDir . '/packages'),
    'packageAssetDir'       => Env::string('WP_UPDATE_SERVER_PACKAGE_ASSET_DIR', 'public/package-assets'),
    'logDir'                => Env::string('WP_UPDATE_SERVER_LOG_DIR', $storageDir . '/logs'),
    'signDownloads'         => Env::bool('WP_UPDATE_SERVER_SIGN_DOWNLOADS', false),
    'defaultCacheTtl'       => Env::int('WP_UPDATE_SERVER_CACHE_TTL', 3600),
    'downloadSignatureTtl'  => Env::int('WP_UPDATE_SERVER_DOWNLOAD_SIGNATURE_TTL', 900),
    'downloadLimit'         => Env::int('WP_UPDATE_SERVER_DOWNLOAD_LIMIT', 60),
    'downloadWindowSeconds' => Env::int('WP_UPDATE_SERVER_DOWNLOAD_WINDOW_SECONDS', 3600),
    'downloadSecret'        => Env::string('WP_UPDATE_SERVER_SECRET'),
    'trustedProxies'        => Env::list('WP_UPDATE_SERVER_TRUSTED_PROXIES'),
    'trustedProxyHeaders'   => Env::list(
        'WP_UPDATE_SERVER_TRUSTED_PROXY_HEADERS',
        ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP']
    ),
    'logMaxBytes'           => Env::int('WP_UPDATE_SERVER_LOG_MAX_BYTES', 10485760),
];
