<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands()
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Required for signed URLs to validate correctly behind reverse proxies / tunnels (cloudflared).
        // Without this, Laravel thinks the request scheme is "http" and rejects "https"-signed URLs.
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\ShopifyImagesOnlyMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
