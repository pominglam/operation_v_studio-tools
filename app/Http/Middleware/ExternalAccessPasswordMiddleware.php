<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Maintenance\ExternalAccessAuthService;
use App\Services\Maintenance\ExternalAccessSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExternalAccessPasswordMiddleware
{
    /**
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Never gate the images-only instance (it should 404 everything except /shopify-images/*).
        if ((bool) config('app.shopify_images_only', false)) {
            return $next($request);
        }

        if (! $this->isExternalRequest($request)) {
            // Local access: no login prompt.
            $request->attributes->set('external_access_role', ExternalAccessAuthService::ROLE_ADMIN);

            return $next($request);
        }

        $settings = app(ExternalAccessSettingsService::class);
        if (! $settings->isEnabled()) {
            abort(404);
        }

        $auth = app(ExternalAccessAuthService::class);
        if (! $auth->isPasswordConfigured()) {
            abort(404);
        }

        $cookieVal = $request->cookies->get(ExternalAccessAuthService::COOKIE_NAME);
        if (! is_string($cookieVal) || trim($cookieVal) === '') {
            $cookieVal = $this->cookieFromHeader($request->headers->get('Cookie'), ExternalAccessAuthService::COOKIE_NAME);
        }

        $role = $auth->resolveAuthorizedRole(is_string($cookieVal) ? $cookieVal : null);
        if (is_string($role) && $role !== '') {
            $request->attributes->set('external_access_role', $role);
            if ($auth->isEmployeeRole($role) && ! $this->isEmployeeAllowedPath($request)) {
                if ($this->isApiPath($request)) {
                    return response()->json(['ok' => false, 'error' => 'not_found'], 404);
                }
                abort(404);
            }

            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');
        // Allow rendering/submitting the login page itself (otherwise we'd loop).
        if (str_starts_with($path, '/external-login')) {
            return $next($request);
        }
        if (str_starts_with($path, '/api/')) {
            return response()->json(['ok' => false, 'error' => 'external_auth_required'], 401);
        }

        // Redirect to login page.
        $nextUrl = $request->getRequestUri();
        $login = '/external-login?next='.rawurlencode($nextUrl);

        return redirect($login);
    }

    private function isApiPath(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        return str_starts_with($path, '/api/');
    }

    private function isEmployeeAllowedPath(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        if (str_starts_with($path, '/build/')) {
            return true;
        }
        if (str_starts_with($path, '/external-login')) {
            return true;
        }
        if ($path === '/favicon.ico') {
            return true;
        }
        if ($path === '/up') {
            return true;
        }

        if (str_starts_with($path, '/api/')) {
            return $this->isEmployeeAllowedApiPath($path);
        }

        if ($path === '/' || $path === '/employee' || $path === '/employee/') {
            return true;
        }
        if (str_starts_with($path, '/employee/inventory-count')) {
            return true;
        }

        return false;
    }

    private function isEmployeeAllowedApiPath(string $path): bool
    {
        if (str_starts_with($path, '/api/v1/inventory-check/employee')) {
            return true;
        }

        // Allow reading image assets for scan result cards.
        if (preg_match('#^/api/v1/product-assets/\d+/view$#', $path) === 1) {
            return true;
        }

        return false;
    }

    private function isExternalRequest(Request $request): bool
    {
        // Prefer forwarded host headers (cloudflared / reverse proxies).
        $forwarded = $request->headers->get('X-Forwarded-Host');
        $host = is_string($forwarded) && trim($forwarded) !== ''
            ? $forwarded
            : (string) $request->getHost();

        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }

        return str_ends_with($host, '.trycloudflare.com');
    }

    private function cookieFromHeader(?string $rawCookieHeader, string $name): ?string
    {
        $rawCookieHeader = is_string($rawCookieHeader) ? trim($rawCookieHeader) : '';
        $name = trim($name);
        if ($rawCookieHeader === '' || $name === '') {
            return null;
        }

        foreach (explode(';', $rawCookieHeader) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $kv = explode('=', $part, 2);
            $k = trim((string) ($kv[0] ?? ''));
            if ($k !== $name) {
                continue;
            }
            $v = (string) ($kv[1] ?? '');
            $v = trim($v);

            return $v !== '' ? $v : null;
        }

        return null;
    }
}
