<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlamodPreorder;

$checks = [
    ['label' => 'Ultraman series', 'q' => fn () => PlamodPreorder::query()->active()->where('series', 'Ultraman')],
    ['label' => 'Mecha Collection category', 'q' => fn () => PlamodPreorder::query()->active()->where('category', 'Mecha Collection')],
    ['label' => 'Blue Archive series', 'q' => fn () => PlamodPreorder::query()->active()->where('series', 'Blue Archive')],
    ['label' => 'DC Comics series', 'q' => fn () => PlamodPreorder::query()->active()->where('series', 'DC Comics')],
    ['label' => 'Builder Parts category', 'q' => fn () => PlamodPreorder::query()->active()->where('category', 'Builder Parts')],
    ['label' => 'SD BB', 'q' => fn () => PlamodPreorder::query()->active()->where('series', 'SD BB')],
    ['label' => 'Sanrio (hub figure)', 'q' => fn () => PlamodPreorder::query()->active()->where('series', 'Sanrio')],
];

foreach ($checks as $check) {
    $q = $check['q']();
    echo "=== {$check['label']} (n={$q->count()}) ===\n";
    foreach ($q->limit(3)->get(['sku', 'manufacturer', 'series', 'category']) as $r) {
        echo "  {$r->sku} | mfr={$r->manufacturer} | series={$r->series} | cat={$r->category}\n";
    }
    $mfrs = $q->selectRaw('manufacturer, COUNT(*) c')->groupBy('manufacturer')->pluck('c', 'manufacturer');
    echo '  manufacturers: '.json_encode($mfrs->all())."\n\n";
}
