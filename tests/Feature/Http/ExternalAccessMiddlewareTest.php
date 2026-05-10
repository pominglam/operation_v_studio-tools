<?php

declare(strict_types=1);

use App\Http\Middleware\ExternalAccessPasswordMiddleware;
use App\Services\Maintenance\ExternalAccessAuthService;
use App\Services\Maintenance\ExternalAccessSettingsService;

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
    $setCookie = (string) ($login->headers->get('Set-Cookie') ?? '');
    expect($setCookie)->toContain(ExternalAccessAuthService::COOKIE_NAME.'=');
    expect($setCookie)->toMatch('/'.preg_quote(ExternalAccessAuthService::COOKIE_NAME, '/').'=admin%7C[a-f0-9]{64}/i');

    $cookieVal = $auth->expectedCookieValue();
    expect($cookieVal)->not->toBeNull();

    $res = $this
        ->withHeader('Host', 'custom-login.example.test')
        ->withHeader('Cookie', ExternalAccessAuthService::COOKIE_NAME.'='.(string) $cookieVal)
        ->getJson('/api/v1/job-batches');

    $res->assertStatus(200);
});
