<?php

declare(strict_types=1);

use App\Services\Products\ProductTypeDerivationService;

it('derives expected product types from name using mapping rules', function (): void {
    $svc = app(ProductTypeDerivationService::class);

    expect($svc->deriveFromName('Orphans HG 1/144 Something'))->toBe('Orphans HG');
    expect($svc->deriveFromName('BB368 OO Gundam'))->toBe('SD');
    expect($svc->deriveFromName('FULL MECHANICS 1/100 Gundam'))->toBe('FM');
    expect($svc->deriveFromName('Bandai RE 1/100 Bawoo'))->toBe('RE');
    expect($svc->deriveFromName('EX-STANDARD Hello'))->toBe('EX-Standard');
    expect($svc->deriveFromName('EX-Standard Hello'))->toBe('EX-Standard');
    expect($svc->deriveFromName('SDBF Star Winning Gundam'))->toBe('SDBF');
    expect($svc->deriveFromName('Foo SD Bar'))->toBe('SD');
    expect($svc->deriveFromName('MGEX STRIKE FREEDOM GUNDAM'))->toBe('MGEX');
    expect($svc->deriveFromName('GodHand - Nipper PN-125'))->toBe('NIPPER');
    expect($svc->deriveFromName('Sanding Sponge 600'))->toBe('SANDING');
    expect($svc->deriveFromName('HG 1/144 Action Base 5'))->toBe('ACTION BASE');
    expect($svc->deriveFromName('Pokémon Model Kit'))->toBe('POKEMON');
    expect($svc->deriveFromName('Pokemon Model Kit'))->toBe('POKEMON');
    expect($svc->deriveFromName('POKEMON Model Kit'))->toBe('POKEMON');
    expect($svc->deriveFromName('Figure-rise Standard Something'))->toBe('Figure-rise');
    expect($svc->deriveFromName('Mega Size Model'))->toBe('MEGA');
    expect($svc->deriveFromName('Sanding Stick 600'))->toBe('SANDING');
    expect($svc->deriveFromName('PLAMAX Something'))->toBe('PLAMAX');
});

it('falls back to common grade prefixes when no specific mapping exists', function (): void {
    $svc = app(ProductTypeDerivationService::class);

    expect($svc->deriveFromName('HG 1/144 Gundam'))->toBe('HG');
    expect($svc->deriveFromName('RE 1/100 Bawoo'))->toBe('RE');
    expect($svc->deriveFromName('RG 1/144 Gundam'))->toBe('RG');
    expect($svc->deriveFromName('SD Gundam'))->toBe('SD');
    expect($svc->deriveFromName('Totally Unknown Product'))->toBeNull();
});
