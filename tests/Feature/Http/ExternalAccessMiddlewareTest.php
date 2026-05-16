<?php

declare(strict_types=1);

use App\Http\Middleware\ExternalAccessPasswordMiddleware;
use App\Services\Maintenance\ExternalAccessAuthService;
use App\Services\Maintenance\ExternalAccessSettingsService;
use Illuminate\Http\Request;

it('does not prompt for loopback local access', function (): void {
    app(ExternalAccessSettingsService::class)->setEnabled(false);

    $res = $this->withHeader('Host', 'localhost')->get('/');
    $res->assertStatus(200);
});

it('treats loopback IPs as bypass hosts', function (string $host, bool $expect): void {
    expect(ExternalAccessPasswordMiddleware::hostIsLoopbackBypass($host))->toBe($expect);
})->with([
    ['localhost', true],
    ['LOCALHOST', true],
    ['localhost:8020', true],
    ['127.0.0.1', true],
    ['127.0.255.254', true],
    ['127.0.0.1:8020', true],
    ['::1', true],
    ['[::1]', true],
    ['[::1]:8020', true],
    ['abc.trycloudflare.com', false],
    ['ovs.centredentairevsl.com', false],
    ['192.168.1.1', false],
    ['10.0.0.5', false],
]);

it('returns 404 for non-loopback hosts when external access disabled', function (): void {
    app(ExternalAccessSettingsService::class)->setEnabled(false);

    $res = $this->withHeader('X-Forwarded-Host', 'abc.trycloudflare.com')->get('/');
    $res->assertStatus(404);

    $res2 = $this->withHeader('Host', 'ovs.example.test')->withoutHeader('X-Forwarded-Host')->get('/');
    $res2->assertStatus(404);
});

it('requires auth for non-loopback API requests when enabled', function (): void {
    config(['app.external_access_password' => 'testpw']);

    app(ExternalAccessSettingsService::class)->setEnabled(true);

    $res = $this->withHeader('X-Forwarded-Host', 'abc.trycloudflare.com')->getJson('/api/v1/job-batches');
    $res->assertStatus(401);
    $res->assertJsonPath('error', 'external_auth_required');

    $res2 = $this->withHeader('Host', 'custom-dns.example')->getJson('/api/v1/job-batches');
    $res2->assertStatus(401)->assertJsonPath('error', 'external_auth_required');
});

it('does not redirect shopify oauth callback to external login through tunnel', function (): void {
    config(['app.external_access_password' => 'testpw']);
    app(ExternalAccessSettingsService::class)->setEnabled(true);

    $res = $this
        ->withHeader('X-Forwarded-Host', 'abc.trycloudflare.com')
        ->get('/shopify/oauth/callback');

    $res->assertStatus(403)
        ->assertHeaderMissing('Location');
});

it('allows external access after login for this session', function (): void {
    config(['app.external_access_password' => 'testpw']);

    $auth = app(ExternalAccessAuthService::class);
    app(ExternalAccessSettingsService::class)->setEnabled(true);

    $login = $this
        ->withHeader('Host', 'custom-login.example.test')
        ->post('/external-login', [
            'password' => 'testpw',
            'next' => '/products',
        ]);

    $login->assertStatus(302);
    // Regression: cookie should be a raw role|HMAC (not encrypted by Laravel cookie middleware).
    /** @var list<string|string[]> $cookies */
    $cookies = $login->headers->all('set-cookie');
    $setCookie = implode("\n", array_map(static fn (string|array $c): string => is_array($c) ? implode('', $c) : $c, $cookies));

    expect($setCookie)->toContain(ExternalAccessAuthService::COOKIE_NAME.'=');
    expect($setCookie)->toMatch('/'.preg_quote(ExternalAccessAuthService::COOKIE_NAME, '/').'=admin%7C[a-f0-9]{64}/i');
    expect(stripos($setCookie, 'SameSite=Lax') !== false)->toBeTrue();

    $cookieVal = $auth->expectedCookieValue();
    expect($cookieVal)->not->toBeNull();

    $res = $this
        ->withHeader('Host', 'custom-login.example.test')
        ->withHeader('Cookie', ExternalAccessAuthService::COOKIE_NAME.'='.(string) $cookieVal)
        ->getJson('/api/v1/job-batches');

    $res->assertStatus(200);
});

it('sets SameSite=None on HTTPS proxied tunnel hosts after external login', function (): void {
    config(['app.external_access_password' => 'tunnelpw']);

    app(ExternalAccessSettingsService::class)->setEnabled(true);

    $login = $this
        ->withHeader('X-Forwarded-Host', 'abc.trycloudflare.com')
        ->withHeader('X-Forwarded-Proto', 'https')
        ->post('/external-login', [
            'password' => 'tunnelpw',
            'next' => '/',
        ]);

    $login->assertStatus(302);

    /** @var list<string|string[]> $cookies */
    $cookies = $login->headers->all('set-cookie');
    $setCookie = implode("\n", array_map(static fn (string|array $c): string => is_array($c) ? implode('', $c) : $c, $cookies));

    expect(stripos($setCookie, 'SameSite=None') !== false)->toBeTrue()
        ->and(stripos($setCookie, 'Secure') !== false)->toBeTrue();
});

it('resolves lax vs none SameSite helpers for iframe-friendly cookies', function (): void {
    $loopbackReq = Request::create('http://127.0.0.1:8020/external-login', 'POST', [], [], [], [
        'HTTP_HOST' => '127.0.0.1:8020',
    ]);
    expect(ExternalAccessPasswordMiddleware::externalAuthCookieSameSite($loopbackReq))->toBe('lax');

    $tunnelReq = Request::create('https://php.test/external-login', 'POST', [], [], [], [
        'HTTPS' => 'on',
        'HTTP_HOST' => 'php.test',
        'HTTP_X_FORWARDED_HOST' => 'abc.trycloudflare.com',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);
    expect(ExternalAccessPasswordMiddleware::externalAuthCookieSameSite($tunnelReq))->toBe('none');
});
