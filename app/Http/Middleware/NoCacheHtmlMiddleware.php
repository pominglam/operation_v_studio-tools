<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NoCacheHtmlMiddleware
{
    /**
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent browsers from caching the SPA HTML shell. If the HTML is cached, it can keep
        // referencing an older Vite manifest + hashed JS bundle even after deploy/rebuild.
        $contentType = (string) ($response->headers->get('Content-Type') ?? '');
        if (str_contains(strtolower($contentType), 'text/html')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}

