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

        if ($auth->isAuthorizedCookie(is_string($cookieVal) ? $cookieVal : null)) {
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

    private function isExternalRequest(Request $request): bool
    {
        // Prefer forwarded host headers (cloudflared / reverse proxies).
        $forwarded = $request->headers->get('X-Forwarded-Host');
        $host = is_string($forwarded) && trim($forwarded) !== ''
            ? $forwarded
            : (string) $request->getHost();

        $host = strtolower(trim($host));
        if ($host === '') return false;
        return str_ends_with($host, '.trycloudflare.com');
    }

    private function cookieFromHeader(?string $rawCookieHeader, string $name): ?string
    {
        $rawCookieHeader = is_string($rawCookieHeader) ? trim($rawCookieHeader) : '';
        $name = trim($name);
        if ($rawCookieHeader === '' || $name === '') return null;

        foreach (explode(';', $rawCookieHeader) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $kv = explode('=', $part, 2);
            $k = trim((string) ($kv[0] ?? ''));
            if ($k !== $name) continue;
            $v = (string) ($kv[1] ?? '');
            $v = trim($v);
            return $v !== '' ? $v : null;
        }

        return null;
    }
}

