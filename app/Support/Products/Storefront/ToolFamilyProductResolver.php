<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class ToolFamilyProductResolver
{
    /**
     * @return 'brushes'|'drills'|'tweezers'|'scribing'|'adhesives'|'workshop-misc'|null
     */
    public function resolveDepartment(Product $product): ?string
    {
        $sku = strtoupper(trim((string) $product->sku));
        if ($this->isExcluded($sku)) {
            return null;
        }

        if ($this->isWorkshopMisc($sku)) {
            return StorefrontDepartment::WORKSHOP_MISC;
        }

        if ($this->isAdhesive($sku)) {
            return StorefrontDepartment::ADHESIVES;
        }

        if ($this->isBrush($product, $sku)) {
            return StorefrontDepartment::BRUSHES;
        }

        if ($this->isDrill($product, $sku)) {
            return StorefrontDepartment::DRILLS;
        }

        if ($this->isTweezer($product, $sku)) {
            return StorefrontDepartment::TWEEZERS;
        }

        if ($this->isScribingTool($product, $sku)) {
            return StorefrontDepartment::SCRIBING;
        }

        return null;
    }

    /**
     * @return 'hand'|'anti-static'|null
     */
    public function resolveBrushType(Product $product): ?string
    {
        if ($this->resolveDepartment($product) !== StorefrontDepartment::BRUSHES) {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));

        if (in_array($sku, ['MS-81', 'MS-82', 'MS-83'], true) || str_contains($description, 'anti static')) {
            return 'anti-static';
        }

        return 'hand';
    }

    /**
     * @return 'hand-drill'|'bit-set'|'bit'|null
     */
    public function resolveDrillType(Product $product): ?string
    {
        if ($this->resolveDepartment($product) !== StorefrontDepartment::DRILLS) {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));

        if (in_array($sku, ['MS-25', 'MS-25B'], true)) {
            return 'hand-drill';
        }

        if (in_array($sku, ['MS-155', 'MS-156', 'MS-158'], true)) {
            return 'bit-set';
        }

        return 'bit';
    }

    /**
     * @return 'straight'|'curved'|'flat'|'point'|null
     */
    public function resolveTweezerStyle(Product $product): ?string
    {
        if ($this->resolveDepartment($product) !== StorefrontDepartment::TWEEZERS) {
            return null;
        }

        $description = strtolower(trim((string) $product->description));

        if (str_contains($description, 'curve flat') || str_contains($description, 'curved flat')) {
            return 'flat';
        }

        if (str_contains($description, 'curve point') || str_contains($description, 'curved point')) {
            return 'point';
        }

        if (str_contains($description, 'curve')) {
            return 'curved';
        }

        if (str_contains($description, 'flat')) {
            return 'flat';
        }

        if (str_contains($description, 'point')) {
            return 'point';
        }

        return 'straight';
    }

    /**
     * @return 'ultra-precision'|'thick-wall'|null
     */
    public function resolveTweezerLine(Product $product): ?string
    {
        if ($this->resolveDepartment($product) !== StorefrontDepartment::TWEEZERS) {
            return null;
        }

        return (new StediTweezerTitleResolver)->resolveLine($product);
    }

    /**
     * @return 'scriber'|'pusher'|'handle'|'needle'|'scraper'|null
     */
    public function resolveScribingType(Product $product): ?string
    {
        if ($this->resolveDepartment($product) !== StorefrontDepartment::SCRIBING) {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));

        if (preg_match('/^GS-/', $sku) === 1 || $sku === 'MS-159' || str_contains($description, 'scraper')) {
            return 'scraper';
        }

        if (preg_match('/^MS-39/', $sku) === 1 || str_contains($description, 'pusher handle') || str_contains($description, 'scriber handle')) {
            return 'handle';
        }

        if (preg_match('/^MS-3[0-8]$/', $sku) === 1 || str_contains($description, 'pusher')) {
            return 'pusher';
        }

        if (in_array($sku, ['MS-23', 'MS-27'], true) || str_contains($description, 'needle') || str_contains($description, 'push knife')) {
            return 'needle';
        }

        return 'scriber';
    }

    /**
     * @return 'cement'|null
     */
    public function resolveAdhesiveType(Product $product): ?string
    {
        if ($this->resolveDepartment($product) !== StorefrontDepartment::ADHESIVES) {
            return null;
        }

        return 'cement';
    }

    private function isExcluded(string $sku): bool
    {
        return str_starts_with($sku, 'E2E-');
    }

    private function isWorkshopMisc(string $sku): bool
    {
        return in_array($sku, [
            'MS-58',
            'PT-MPS',
            'TZ-01',
            'GNK-01',
            'PM-001',
        ], true);
    }

    private function isAdhesive(string $sku): bool
    {
        return in_array($sku, ['ETC-01', 'ETC-02', 'MG-01', 'MG-02'], true);
    }

    private function isBrush(Product $product, string $sku): bool
    {
        $type = strtoupper(trim((string) ($product->type ?? '')));
        $description = strtolower(trim((string) $product->description));

        if ($type === 'BRUSHES') {
            return true;
        }

        if (in_array($sku, ['MS-81', 'MS-82', 'MS-83'], true)) {
            return true;
        }

        if (preg_match('/^MS-13[0-5]$/', $sku) === 1) {
            return true;
        }

        return str_contains($description, 'brush') && ! str_contains($description, 'airbrush');
    }

    private function isDrill(Product $product, string $sku): bool
    {
        $type = strtoupper(trim((string) ($product->type ?? '')));

        if ($type === 'DRILL') {
            return true;
        }

        if (in_array($sku, ['MS-25', 'MS-25B'], true)) {
            return true;
        }

        if (preg_match('/^MS-15[5-8]$/', $sku) === 1 || preg_match('/^MS-18[5-9]$/', $sku) === 1) {
            return true;
        }

        $description = strtolower(trim((string) $product->description));

        return str_contains($description, 'drill bit') || str_contains($description, 'hand drill');
    }

    private function isTweezer(Product $product, string $sku): bool
    {
        $type = strtoupper(trim((string) ($product->type ?? '')));
        $description = strtolower(trim((string) $product->description));

        if ($type === 'TWEEZERS') {
            return true;
        }

        if (preg_match('/^MS-16[0-3]$/', $sku) === 1) {
            return true;
        }

        if (in_array($sku, ['MS-11', 'MS-12', 'MS-14', 'MS-15', 'MS-16', 'MS-17'], true)) {
            return true;
        }

        return str_contains($description, 'tweezer');
    }

    private function isScribingTool(Product $product, string $sku): bool
    {
        if (in_array($sku, ['MS-03', 'MS-06', 'MS-23', 'MS-27'], true)) {
            return false;
        }

        $type = strtoupper(trim((string) ($product->type ?? '')));
        $description = strtolower(trim((string) $product->description));

        if ($type === 'SCRIBING') {
            return true;
        }

        if (preg_match('/^GS-/', $sku) === 1) {
            return true;
        }

        if (preg_match('/^MS-15[0-4]$/', $sku) === 1) {
            return true;
        }

        if ($sku === 'MS-159') {
            return true;
        }

        if (preg_match('/^MS-3[0-9]/', $sku) === 1) {
            return true;
        }

        return str_contains($description, 'scriber')
            || str_contains($description, 'pusher')
            || str_contains($description, 'scraper bit');
    }
}
