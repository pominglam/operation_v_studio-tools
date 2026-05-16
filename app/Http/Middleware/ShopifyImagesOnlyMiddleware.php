<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShopifyImagesOnlyMiddleware
{
    /**
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('app.shopify_images_only', false)) {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');
        // Some nginx+php-fpm setups surface routed paths as "/index.php/..." instead of "/...".
        // Normalize this variant so the images-only gate works consistently across runtimes.
        if (str_starts_with($path, '/index.php/')) {
            $path = '/'.ltrim(substr($path, strlen('/index.php/')), '/');
        }
        if (str_starts_with($path, '/shopify-images/')) {
            return $next($request);
        }

        // ERP webhook ingress must work on the same routed host/process as the main app worker.
        if ($path === '/api/webhooks/shopify') {
            return $next($request);
        }

        abort(404);
    }
}
