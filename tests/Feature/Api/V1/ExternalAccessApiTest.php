<?php

declare(strict_types=1);

use App\Services\Maintenance\ExternalAccessSettingsService;
use Illuminate\Support\Facades\Bus;

it('returns external access status', function (): void {
    // Ensure the batch lookup doesn't explode in environments without a real queue.
    Bus::fake();

    $res = $this->getJson('/api/v1/maintenance/external-access');
    $res->assertStatus(200);
    $res->assertJsonStructure([
        'data' => [
            'enabled',
            'password_configured',
            'tunnel' => [
                'running',
                'tunnel_url',
                'container_id',
                'error',
            ],
        ],
    ]);
});

it('refuses to enable external access when password is missing', function (): void {
    putenv('EXTERNAL_ACCESS_PASSWORD=');
    $_ENV['EXTERNAL_ACCESS_PASSWORD'] = '';

    // If the env is not set in the test environment, enabling must fail.
    $res = $this->putJson('/api/v1/maintenance/external-access', ['enabled' => true]);
    $res->assertStatus(400);
    $res->assertJsonPath('error', 'password_not_configured');
});

it('can disable external access', function (): void {
    // Force enabled in DB then disable via API.
    app(ExternalAccessSettingsService::class)->setEnabled(true);

    $res = $this->putJson('/api/v1/maintenance/external-access', ['enabled' => false]);
    $res->assertStatus(200);
    $res->assertJsonPath('data.enabled', false);
});
