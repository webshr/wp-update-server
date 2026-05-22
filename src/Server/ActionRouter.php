<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Server;

use Webshr\WpUpdateServer\Http\Request;
use Webshr\WpUpdateServer\Http\Response;

final class ActionRouter
{
    public function __construct(private readonly UpdateServer $server)
    {
    }

    public function route(Request $request): Response
    {
        $segments = $request->pathSegments();

        if ($request->method === 'GET' && count($segments) === 2 && $segments[0] === 'metadata') {
            return $this->server->metadata($request, $segments[1]);
        }

        if ($request->method === 'GET' && count($segments) === 3 && $segments[0] === 'download') {
            return $this->server->download($request, $segments[1], $segments[2]);
        }

        if ($request->method === 'POST' && count($segments) === 3 && $segments[0] === 'license' && $segments[2] === 'activate') {
            return $this->server->activateLicense($request, $segments[1]);
        }

        if ($request->method === 'POST' && count($segments) === 3 && $segments[0] === 'license' && $segments[2] === 'deactivate') {
            return $this->server->deactivateLicense($request, $segments[1]);
        }

        if (in_array($request->method, ['GET', 'POST'], true) && count($segments) === 3 && $segments[0] === 'license' && $segments[2] === 'check') {
            return $this->server->checkLicense($request, $segments[1]);
        }

        if (in_array($request->method, ['DELETE', 'POST', 'GET'], true) && $segments === ['cache', 'clear']) {
            return $this->server->clearCache($request);
        }

        return Response::json([
            'error' => 'Route not found.',
            'routes' => [
                'GET /metadata/{slug}',
                'GET /download/{slug}/{version}',
                'POST /license/{slug}/activate',
                'POST /license/{slug}/deactivate',
                'GET|POST /license/{slug}/check',
                'POST /cache/clear',
            ],
        ], 404);
    }
}
