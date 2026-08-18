<?php

declare(strict_types=1);

use App\Support\Pricing\CharmPricingCalculator;

it('computes charm price from cost times multiplier rounded up to x99', function (): void {
    expect(CharmPricingCalculator::sellingPriceX99FromCost('4.00', '1.5'))->toBe('6.99');
    expect(CharmPricingCalculator::sellingPriceX99FromCost('10.00', '1.5'))->toBe('15.99');
    expect(CharmPricingCalculator::sellingPriceX99FromCost('12.35', '1.5'))->toBe('18.99');
});

it('prefers one x99 tier below the formula price when formula is over 1.55x and reduced stays at least 1.45x', function (): void {
    expect(CharmPricingCalculator::applyHighMultiplierReduction('15.99', '10.00'))->toBe('14.99');
    expect(CharmPricingCalculator::applyHighMultiplierReduction('6.99', '4.00'))->toBe('5.99');
    expect(CharmPricingCalculator::applyHighMultiplierReduction('11.99', '7.44'))->toBe('10.99');
});

it('keeps formula price when step 1 is not over 1.55x', function (): void {
    expect(CharmPricingCalculator::applyHighMultiplierReduction('18.99', '12.35'))->toBe('18.99');
    expect(CharmPricingCalculator::applyHighMultiplierReduction('174.99', '116.22'))->toBe('174.99');
    expect(CharmPricingCalculator::applyHighMultiplierReduction('82.99', '55.00'))->toBe('82.99');
    expect(CharmPricingCalculator::applyHighMultiplierReduction('74.99', '49.97'))->toBe('74.99');
    expect(CharmPricingCalculator::applyHighMultiplierReduction('99.99', '66.47'))->toBe('99.99');
    expect(CharmPricingCalculator::applyHighMultiplierReduction('9.99', '6.63'))->toBe('9.99');
});

it('keeps formula price when a one dollar reduction would fall below 1.45x', function (): void {
    expect(CharmPricingCalculator::applyHighMultiplierReduction('1.99', '0.70'))->toBe('1.99');
});

it('returns null when selling price or unit cost is missing', function (): void {
    expect(CharmPricingCalculator::applyHighMultiplierReduction(null, '10.00'))->toBeNull();
    expect(CharmPricingCalculator::applyHighMultiplierReduction('15.99', null))->toBe('15.99');
    expect(CharmPricingCalculator::applyHighMultiplierReduction('15.99', ''))->toBe('15.99');
});
