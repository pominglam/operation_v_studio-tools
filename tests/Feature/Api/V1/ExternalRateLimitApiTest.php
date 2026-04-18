<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

it('gets and updates the global external hits per minute setting', function (): void {
    Cache::forget('settings:external_hits_per_minute');

    $res1 = $this->getJson('/api/v1/maintenance/external-rate-limit');
    $res1->assertStatus(200);
    $res1->assertJsonStructure(['data' => ['hits_per_minute']]);

    $res2 = $this->putJson('/api/v1/maintenance/external-rate-limit', [
        'hits_per_minute' => 17,
    ]);
    $res2->assertStatus(200);
    $res2->assertJsonPath('data.hits_per_minute', 17);

    $res3 = $this->getJson('/api/v1/maintenance/external-rate-limit');
    $res3->assertStatus(200);
    $res3->assertJsonPath('data.hits_per_minute', 17);
});
