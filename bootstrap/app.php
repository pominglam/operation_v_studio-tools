<?php

use App\Services\Maintenance\ExternalAccessAuthService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Ensure newly created files/directories are readable across containers.
// (e.g. the shopify_images_php worker runs as a different user than the queue/web containers.)
if (function_exists('umask')) {
    umask(0022); // dirs: 0755, files: 0644 (default create modes)
}

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands()
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Don't encrypt the external-access cookie so our lightweight gate can validate it
        // even when sessions/auth aren't used.
        $middleware->encryptCookies([ExternalAccessAuthService::COOKIE_NAME]);

        // Required for signed URLs to validate correctly behind reverse proxies / tunnels (cloudflared).
        // Without this, Laravel thinks the request scheme is "http" and rejects "https"-signed URLs.
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\ShopifyImagesOnlyMiddleware::class);
        $middleware->append(\App\Http\Middleware\ExternalAccessPasswordMiddleware::class);
        // Prevent caching the SPA HTML shell (stale Vite manifest / JS bundle).
        $middleware->append(\App\Http\Middleware\NoCacheHtmlMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
