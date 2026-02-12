<?php

declare(strict_types=1);

use App\Services\Maintenance\ExternalAccessAuthService;
use App\Services\Maintenance\ExternalAccessSettingsService;

it('does not prompt for local access', function (): void {
    app(ExternalAccessSettingsService::class)->setEnabled(false);

    $res = $this->get('/');
    $res->assertStatus(200);
});

it('returns 404 for external requests when external access disabled', function (): void {
    app(ExternalAccessSettingsService::class)->setEnabled(false);

    $res = $this->withHeader('X-Forwarded-Host', 'abc.trycloudflare.com')->get('/');
    $res->assertStatus(404);
});

it('requires auth for external API requests when enabled', function (): void {
    putenv('EXTERNAL_ACCESS_PASSWORD=testpw');
    $_ENV['EXTERNAL_ACCESS_PASSWORD'] = 'testpw';
    $auth = app(ExternalAccessAuthService::class);

    app(ExternalAccessSettingsService::class)->setEnabled(true);

    $res = $this->withHeader('X-Forwarded-Host', 'abc.trycloudflare.com')->getJson('/api/v1/job-batches');
    $res->assertStatus(401);
    $res->assertJsonPath('error', 'external_auth_required');
});

it('allows external access after login for this session', function (): void {
    putenv('EXTERNAL_ACCESS_PASSWORD=testpw');
    $_ENV['EXTERNAL_ACCESS_PASSWORD'] = 'testpw';

    $auth = app(ExternalAccessAuthService::class);
    app(ExternalAccessSettingsService::class)->setEnabled(true);

    $login = $this
        ->withHeader('X-Forwarded-Host', 'abc.trycloudflare.com')
        ->post('/external-login', [
            'password' => 'testpw',
            'next' => '/products',
        ]);

    $login->assertStatus(302);
    // Regression: cookie should be a raw HMAC (not encrypted by Laravel cookie middleware).
    $setCookie = (string) ($login->headers->get('Set-Cookie') ?? '');
    expect($setCookie)->toContain(ExternalAccessAuthService::COOKIE_NAME.'=');
    expect($setCookie)->toMatch('/'.preg_quote(ExternalAccessAuthService::COOKIE_NAME, '/').'=[a-f0-9]{64}/i');

    $cookieVal = $auth->expectedCookieValue();
    expect($cookieVal)->not->toBeNull();

    $res = $this
        ->withHeader('X-Forwarded-Host', 'abc.trycloudflare.com')
        ->withHeader('Cookie', ExternalAccessAuthService::COOKIE_NAME.'='.(string) $cookieVal)
        ->getJson('/api/v1/job-batches');

    $res->assertStatus(200);
});

