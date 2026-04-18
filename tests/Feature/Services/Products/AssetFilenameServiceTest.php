<?php

declare(strict_types=1);

use App\Services\Products\AssetFilenameService;

it('generates an ASCII-only slug with allowed chars', function (): void {
    $svc = new AssetFilenameService;

    $slug = $svc->buildTitleSlug('Gundam Seven Sword® Front Édition!');
    expect($slug)->toBe('gundam-seven-sword-front-edition');

    $fn = $svc->buildSeoFilename($slug, 1, 123, 'PNG');
    expect($fn)->toBe('gundam-seven-sword-front-edition-01-123.png');
});
