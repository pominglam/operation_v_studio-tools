<?php

declare(strict_types=1);

it('exposes price research filter options including disabled_site_keys', function (): void {
    config()->set('price_research.disabled_site_keys', ['canadian_gundam', '']);

    $res = $this->getJson('/api/v1/price-research/filter-options');
    $res->assertOk()
        ->assertJsonPath('data.disabled_site_keys.0', 'canadian_gundam')
        ->assertJsonMissing(['key' => 'canadian_gundam'])
        ->assertJsonPath('data.sites.0.key', fn ($v) => is_string($v) && $v !== '');
});
