<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render terminates TLS at its own load balancer and forwards plain
        // HTTP to the container. Without this Laravel reads the scheme off that
        // inner request, decides the site is http, and writes http:// into
        // every generated URL on a page the browser loaded over https — which
        // it then refuses to load as mixed content. The proxy's address is not
        // knowable in advance on a platform that assigns it, so the forwarded
        // headers are trusted rather than a specific IP.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
