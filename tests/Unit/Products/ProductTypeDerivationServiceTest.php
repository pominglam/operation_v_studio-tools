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
    expect($svc->deriveFromName('KERORO - GIRORO ROBO MK2'))->toBe('KERORO');
    expect($svc->deriveFromName('1/1 ZAKUPLA-KUN DX SET'))->toBe('KUN DX');
    expect($svc->deriveFromName('1/1 GUNPLA-KUN DX SET'))->toBe('KUN DX');
    expect($svc->deriveFromName('30MF LIBER ARCHER'))->toBe('30MF');
    expect($svc->deriveFromName('CCS EVANGELION Unit-02 Type II'))->toBe('CCS TOYS');
    expect($svc->deriveFromName('Sazabi (Universal Century Saga)'))->toBe('SAZABI BUST');
    expect($svc->deriveFromName('System Base 001'))->toBe('SYSTEM BASE');
    expect($svc->deriveFromName('LED Unit (Blue)'))->toBe('LED');
    expect($svc->deriveFromName('OPTION PARTS SET GUNPLA 14 (GUNBARREL STRIKER)'))->toBe('OPTION PARTS SET');
    expect($svc->deriveFromName('HG 1/100 VF-31C SIEGFRIED (MIRAGE FARINA JENIUS)'))->toBe('MACROSS');
    expect($svc->deriveFromName('MACROSS Delta VF-31 Siegfried'))->toBe('MACROSS');
    expect($svc->deriveFromName('30MM ARMORED CORE VI FIRES OF RUBICON'))->toBe('ARMORED CORE');
});

it('falls back to common grade prefixes when no specific mapping exists', function (): void {
    $svc = app(ProductTypeDerivationService::class);

    expect($svc->deriveFromName('HG 1/144 Gundam'))->toBe('HG');
    expect($svc->deriveFromName('RE 1/100 Bawoo'))->toBe('RE');
    expect($svc->deriveFromName('RG 1/144 Gundam'))->toBe('RG');
    expect($svc->deriveFromName('SD Gundam'))->toBe('SD');
    expect($svc->deriveFromName('Totally Unknown Product'))->toBeNull();
});
