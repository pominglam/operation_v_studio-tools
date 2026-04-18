<?php

declare(strict_types=1);

use App\Services\Products\Hlj\HljImageAcceptanceService;

require_once __DIR__.'/../../Support/PngTestUtils.php';

it('rejects a wide, very-compressible banner image even if the URL looks like a product image', function (): void {
    $svc = new HljImageAcceptanceService;

    $url = 'https://www.hlj.com/productimages/ban/bans64015_0.png';
    $expected = 'bans64015';

    // Large, compressible text chunk keeps file > 10KB while preserving low entropy.
    $bytes = buildPngBytes(1200, 300, str_repeat('A', 30_000));

    $a = $svc->assess($url, $bytes, 'image/png', $expected);
    expect($a['accept'])->toBeFalse();
    expect($a['reason'])->toBeIn(['banner_like_low_entropy', 'very_low_entropy']);
});

it('accepts a square image when entropy is not suspicious', function (): void {
    $svc = new HljImageAcceptanceService;

    $url = 'https://www.hlj.com/productimages/ban/bans64015_2.jpg';
    $expected = 'bans64015';

    // Add random-ish payload to avoid the “very_low_entropy” heuristic.
    $rand = '';
    for ($i = 0; $i < 30_000; $i++) {
        $rand .= chr(65 + ($i % 26));
    }
    $bytes = buildPngBytes(800, 800, str_shuffle($rand));

    $a = $svc->assess($url, $bytes, 'image/png', $expected);
    expect($a['accept'])->toBeTrue();
    expect($a['width'])->toBe(800);
    expect($a['height'])->toBe(800);
    expect(strlen($a['sha256']))->toBe(64);
});
