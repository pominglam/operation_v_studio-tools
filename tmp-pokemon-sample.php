<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlamodPreorder;

$rows = PlamodPreorder::query()->active()->where('series', 'Pokémon')->orderBy('sku')->get();
echo 'Pokémon active rows: '.$rows->count()."\n";
$mfrs = $rows->groupBy(fn ($r) => trim((string) ($r->manufacturer ?: '(blank)')));
foreach ($mfrs as $m => $grp) {
    echo "  {$m}: ".$grp->count()."\n";
}
echo "\nSample:\n";
foreach ($rows->take(8) as $r) {
    echo "{$r->sku} | {$r->manufacturer} | cat={$r->category} | ".substr((string) $r->product_name, 0, 55)."\n";
}

// SD EX/Cross rows
echo "\nSD EX-Standard / Cross Silhouette rows:\n";
$sd = PlamodPreorder::query()->active()->where('manufacturer', 'BANDAI HOBBY')
    ->whereIn('category', ['SD EX-Standard', 'SD Cross Silhouette', 'SD G Generation', 'SD BB'])
    ->get();
foreach ($sd as $r) {
    echo "{$r->sku} | cat={$r->category} | series={$r->series} | ".substr((string) $r->product_name, 0, 50)."\n";
}
if ($sd->isEmpty()) {
    echo "(none by category)\n";
}
