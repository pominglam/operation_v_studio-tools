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
        if (str_starts_with($path, '/shopify-images/')) {
            return $next($request);
        }

        abort(404);
    }
}






