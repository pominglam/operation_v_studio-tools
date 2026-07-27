<?php

declare(strict_types=1);

use App\Support\Products\ShopifyHandleSlugger;

it('converts unicode roman numerals into slug letters', function (): void {
    expect(ShopifyHandleSlugger::slugFromText('30MM 1/144 bEXM-6 ROUNDNOVA Ⅰ'))
        ->toBe('30mm-1144-bexm-6-roundnova-i');
    expect(ShopifyHandleSlugger::slugFromText('30MM 1/144 bEXM-6 ROUNDNOVA Ⅱ'))
        ->toBe('30mm-1144-bexm-6-roundnova-ii');
});

it('converts ascii roman numerals at word boundaries', function (): void {
    expect(ShopifyHandleSlugger::slugFromText('HGUC Ver II kit'))
        ->toBe('hguc-ver-ii-kit');
    expect(ShopifyHandleSlugger::normalizeRomanNumerals('Phase IV complete'))
        ->toBe('Phase iv complete');
});
