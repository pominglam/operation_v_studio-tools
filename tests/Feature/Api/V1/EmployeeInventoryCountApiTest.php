<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductSellingPrice;
use App\Services\Maintenance\ExternalAccessAuthService;
use App\Services\Maintenance\ExternalAccessSettingsService;

it('supports employee scan sessions and admin apply flow', function (): void {
    $p = Product::query()->create([
        'sku' => 'EMP-SCAN-1',
        'barcode' => '1111111111111',
        'description' => 'Employee Scan Product',
        'vendor' => 'Plamod',
        'available_qty' => 3,
        'latest_landed_unit_cost' => '4.50',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '9.99',
    ]);

    Product::query()->create([
        'sku' => 'EMP-SCAN-ARCH',
        'barcode' => '2222222222222',
        'description' => 'Archived Product',
        'vendor' => 'Plamod',
        'archived_at' => now(),
    ]);

    $create = $this->postJson('/api/v1/inventory-check/employee/sessions', [
        'name' => 'E2E Employee Session',
    ])->assertStatus(201);

    $sessionId = (string) ($create->json('data.session.id') ?? '');
    expect($sessionId)->not->toBe('');

    $this->postJson("/api/v1/inventory-check/employee/sessions/{$sessionId}/scan", [
        'barcode' => '1111111111111',
    ])->assertOk();
    $scan2 = $this->postJson("/api/v1/inventory-check/employee/sessions/{$sessionId}/scan", [
        'barcode' => '1111111111111',
    ])->assertOk();
    $items = $scan2->json('data.items') ?? [];
    $matched = collect($items)->firstWhere('sku', 'EMP-SCAN-1');
    expect($matched)->not->toBeNull();
    expect((int) ($matched['quantity'] ?? 0))->toBe(2);
    expect((bool) ($matched['issue_flag'] ?? false))->toBeFalse();

    $issueScan = $this->postJson("/api/v1/inventory-check/employee/sessions/{$sessionId}/scan", [
        'barcode' => '2222222222222',
    ])->assertOk();
    $issueItems = $issueScan->json('data.items') ?? [];
    $issueLine = collect($issueItems)->firstWhere('barcode_scanned', '2222222222222');
    expect($issueLine)->not->toBeNull();
    expect((bool) ($issueLine['issue_flag'] ?? false))->toBeTrue();

    $matchedLineId = (int) ($matched['id'] ?? 0);
    expect($matchedLineId)->toBeGreaterThan(0);
    $this->patchJson("/api/v1/inventory-check/employee/sessions/{$sessionId}/lines/{$matchedLineId}", [
        'quantity' => 5,
        'product_name' => 'Updated From Inventory Count',
    ])->assertOk();

    $this->postJson("/api/v1/inventory-check/{$sessionId}/apply", [])->assertOk();
    expect((int) Product::query()->where('id', $p->id)->value('available_qty'))->toBe(5);
    expect((string) Product::query()->where('id', $p->id)->value('description'))->toBe('Updated From Inventory Count');
});

it('employee role is restricted to employee APIs for external access', function (): void {
    config()->set('app.external_access_password', 'admin-pass-1');
    config()->set('app.external_access_employee_password', 'emp147');

    app(ExternalAccessSettingsService::class)->setEnabled(true);

    $auth = app(ExternalAccessAuthService::class);
    $employeeCookie = (string) $auth->cookieValueForRole(ExternalAccessAuthService::ROLE_EMPLOYEE);
    expect($employeeCookie)->not->toBe('');

    $headers = [
        'X-Forwarded-Host' => 'abc.trycloudflare.com',
        'Cookie' => ExternalAccessAuthService::COOKIE_NAME.'='.$employeeCookie,
    ];
    $this->withHeaders($headers)
        ->getJson('/api/v1/products')
        ->assertStatus(404);

    $this->withHeaders($headers)
        ->postJson('/api/v1/inventory-check/employee/sessions', [])
        ->assertStatus(201);
});

