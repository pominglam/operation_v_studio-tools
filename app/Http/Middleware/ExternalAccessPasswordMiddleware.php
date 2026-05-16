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

        if ($this->isShopifyWebhookIngressPath($request)) {
            return $next($request);
        }

        if ($this->isShopifyOAuthCallbackPath($request)) {
            return $next($request);
        }

        if (self::isLoopbackBypassHostRequest($request)) {
            // Direct local access only (localhost / loopback): no login prompt.
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

    /**
     * Allow Shopify webhook POST ingress without cookie auth (validated via SHOPIFY_WEBHOOK_SECRET + HMAC).
     */
    private function isShopifyWebhookIngressPath(Request $request): bool
    {
        if (! $request->isMethod('POST')) {
            return false;
        }
        $path = '/'.ltrim($request->path(), '/');
        if (str_starts_with($path, '/index.php/')) {
            $path = '/'.ltrim(substr($path, strlen('/index.php/')), '/');
        }

        return $path === '/api/webhooks/shopify';
    }

    /**
     * Merchant browser hits this after Shopify authorization; must bypass tunnel password gate or OAuth never completes.
     */
    private function isShopifyOAuthCallbackPath(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');
        if (str_starts_with($path, '/index.php/')) {
            $path = '/'.ltrim(substr($path, strlen('/index.php/')), '/');
        }

        return $path === '/shopify/oauth/callback';
    }

    public static function isLoopbackBypassHostRequest(Request $request): bool
    {
        return self::hostIsLoopbackBypass(self::resolveInboundHost($request));
    }

    /**
     * Public host as seen outside Docker (Forwarded-Host first), lowercased.
     */
    public static function resolveInboundHost(Request $request): string
    {
        $forwarded = $request->headers->get('X-Forwarded-Host');
        if (is_string($forwarded) && trim($forwarded) !== '') {
            $forwarded = trim(explode(',', $forwarded, 2)[0] ?? '');
        } else {
            $forwarded = '';
        }

        $host = $forwarded !== '' ? $forwarded : (string) $request->getHost();

        return strtolower(trim($host));
    }

    /**
     * Embedded apps (e.g. Shopify admin iframe) need SameSite "none" on HTTPS for this cookie to stick.
     * Lax is kept for loopback / non-HTTPS fallbacks.
     */
    public static function externalAuthCookieSameSite(Request $request): string
    {
        if (self::hostIsLoopbackBypass(self::resolveInboundHost($request))) {
            return 'lax';
        }

        return $request->secure() ? 'none' : 'lax';
    }

    /**
     * True when the inbound host is localhost or IPv4 loopback (127/8) or IPv6 loopback (::1).
     */
    public static function hostIsLoopbackBypass(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }

        // Bracketed IPv6 loopback, optional explicit port ([::1]:8020).
        if (preg_match('/^\[::1\](?::(\d+))?$/', $host) === 1) {
            return true;
        }

        // localhost, optional explicit port (localhost:8020).
        if (preg_match('/^localhost(?::(\d+))?$/', $host) === 1) {
            return true;
        }

        // IPv4 loopback (/8), optional explicit port (127.0.0.1:8020).
        if (preg_match('/^(127(?:\.\d{1,3}){3})(?::(\d+))?$/', $host, $m) === 1) {
            foreach (explode('.', $m[1]) as $octet) {
                if ((int) $octet > 255) {
                    return false;
                }
            }

            return true;
        }

        // Unbracketed IPv6 loopback only (no ambiguous host:port form).
        return $host === '::1';
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
