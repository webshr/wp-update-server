<?php

declare(strict_types=1);

use Webshr\WpUpdateServer\Http\Request;
use Webshr\WpUpdateServer\Http\Response;
use Webshr\WpUpdateServer\Server\ServerFactory;
use Webshr\WpUpdateServer\Support\Environment;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

Environment::load($root);

$factory = new ServerFactory($root);
$config = $factory->config();
$server = $factory->server();
try {
    $request = Request::fromGlobals($config->server->trustedProxies, $config->server->trustedProxyHeaders);
} catch (Throwable $exception) {
    Response::json([ 'error' => $exception->getMessage() ], 400)->send();
}

$server->handle($request)->send();
