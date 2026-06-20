<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ShopifyStorefrontPilotFiltersVerifyCommand extends Command
{
    protected $signature = 'shopify:storefront-pilot-filters-verify';

    protected $description = 'Verify pilot collection filters (width on tapes, no price sidebar).';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/');
        $checks = [
            [
                'name' => 'Tapes page loads',
                'url' => $baseUrl.'/collections/tapes',
                'expect' => ['Width', 'Availability'],
                'reject' => ['facets__summary-label">Price', 'id="Details-filter.v.price'],
            ],
            [
                'name' => 'Tapes width filter assets (10 mm)',
                'url' => $baseUrl.'/collections/tapes?ovs_width=10',
                'expect' => ['ovs-pilot-collection-filters.js', 'ovs_width=10', 'Width'],
                'reject' => [],
            ],
            [
                'name' => 'Decals page loads',
                'url' => $baseUrl.'/collections/decals',
                'expect' => ['Availability', 'Decal Softener'],
                'reject' => ['facets__summary-label">Price', 'id="Details-filter.v.price'],
            ],
            [
                'name' => 'Sanding page loads',
                'url' => $baseUrl.'/collections/sanding',
                'expect' => ['Type', 'Grit', 'ovs-sanding-collection-filters.js', 'Availability'],
                'reject' => ['facets__summary-label">Price', 'id="Details-filter.v.price', 'facets__summary-label">Form', 'facets__summary-label">Product type'],
            ],
            [
                'name' => 'Sanding grit filter assets (medium)',
                'url' => $baseUrl.'/collections/sanding?ovs_grit=medium',
                'expect' => ['ovs_grit=medium', 'Grit'],
                'reject' => [],
            ],
            [
                'name' => 'Nippers & knives page loads',
                'url' => $baseUrl.'/collections/nippers-and-knives',
                'expect' => ['Category', 'Style', 'ovs-cutting-collection-filters.js', 'Availability'],
                'reject' => ['facets__summary-label">Price', 'id="Details-filter.v.price', 'facets__summary-label">Product type'],
            ],
            [
                'name' => 'Nippers category filter assets',
                'url' => $baseUrl.'/collections/nippers-and-knives?ovs_cut_category=nipper',
                'expect' => ['ovs_cut_category=nipper', 'Category'],
                'reject' => [],
            ],
            [
                'name' => 'Paints page loads',
                'url' => $baseUrl.'/collections/paints',
                'expect' => ['Type', 'Application', 'Paint type', 'ovs-paints-collection-filters.js', 'Availability'],
                'reject' => ['facets__summary-label">Product', 'facets__summary-label">Price', 'id="Details-filter.v.price', 'Bundle', 'Clear'],
            ],
            [
                'name' => 'Paints metallic filter assets',
                'url' => $baseUrl.'/collections/paints?ovs_paint_type=metallic',
                'expect' => ['ovs_paint_type=metallic', 'Paint type'],
                'reject' => [],
            ],
            [
                'name' => 'Markers page loads',
                'url' => $baseUrl.'/collections/markers',
                'expect' => ['Type', 'Tip', 'ovs-markers-collection-filters.js', 'Availability'],
                'reject' => ['facets__summary-label">Price', 'id="Details-filter.v.price'],
            ],
            [
                'name' => 'Markers fluorescent filter assets',
                'url' => $baseUrl.'/collections/markers',
                'expect' => ['data-ovs-marker-type="fluorescent"', 'Type'],
                'reject' => [],
            ],
            [
                'name' => 'Brushes page loads',
                'url' => $baseUrl.'/collections/brushes',
                'expect' => ['Brush type', 'ovs-brushes-collection-filters.js', 'data-ovs-brush-type="hand"', 'Availability'],
                'reject' => ['facets__summary-label">Price', 'id="Details-filter.v.price', 'ovs-phase7-collection-filters.js'],
            ],
            [
                'name' => 'Drills page loads',
                'url' => $baseUrl.'/collections/drills',
                'expect' => ['Hand drill', 'ovs-drills-collection-filters.js', 'data-ovs-drill-type="hand-drill"', 'Availability'],
                'reject' => ['facets__summary-label">Price', 'id="Details-filter.v.price', 'ovs-phase7-collection-filters.js'],
            ],
            [
                'name' => 'Tweezers page loads',
                'url' => $baseUrl.'/collections/tweezers',
                'expect' => ['Line', 'Style', 'Ultra-Precision', 'ovs-tweezers-collection-filters.js', 'data-ovs-tweezer-line="ultra-precision"', 'data-ovs-tweezer-style="straight"', 'Availability'],
                'reject' => ['facets__summary-label">Price', 'id="Details-filter.v.price', 'ovs-phase7-collection-filters.js'],
            ],
            [
                'name' => 'Scribing tools page loads',
                'url' => $baseUrl.'/collections/scribing-tools',
                'expect' => ['Type', 'Scriber & Pusher', 'ovs-scribing-tools-collection-filters.js', 'data-ovs-scribing-filter="scriber-pusher"', 'Availability'],
                'reject' => ['facets__summary-label">Price', 'id="Details-filter.v.price', 'ovs-phase7-collection-filters.js'],
            ],
            [
                'name' => 'Airbrush page loads',
                'url' => $baseUrl.'/collections/airbrush',
                'expect' => ['Category', 'Airbrush', 'ovs-airbrush-collection-filters.js', 'data-ovs-airbrush-role="tool"', 'Availability'],
                'reject' => ['facets__summary-label">Price', 'ovs-phase7-collection-filters.js'],
            ],
            [
                'name' => 'Workshop misc page loads',
                'url' => $baseUrl.'/collections/workshop-misc',
                'expect' => ['Availability'],
                'reject' => ['facets__summary-label">Price'],
            ],
            [
                'name' => 'Tools & supplies hub page loads',
                'url' => $baseUrl.'/collections/tools-and-supplies',
                'expect' => ['Category', 'ovs-ts-dept-link', '/collections/brushes', 'Availability', 'ovs-tools-supplies-nav.css'],
                'reject' => ['ovs-tools-and-supplies-collection-filters.js', 'Shop by department', 'ovs-ts-shop-by'],
            ],
            [
                'name' => 'Brushes shelf breadcrumb loads',
                'url' => $baseUrl.'/collections/brushes',
                'expect' => ['aria-label="Breadcrumb"', 'Tools & supplies', '/collections/tools-and-supplies', 'ovs-tools-supplies-nav.css', 'ovs-ts-collection-title'],
                'reject' => ['ovs-ts-shop-by'],
            ],
        ];

        $failed = false;
        foreach ($checks as $check) {
            $html = $this->fetch((string) $check['url']);
            if ($html === null) {
                $this->error("{$check['name']}: fetch failed");
                $failed = true;

                continue;
            }

            $ok = true;
            foreach ($check['expect'] as $needle) {
                if (! str_contains($html, $needle)) {
                    $this->error("{$check['name']}: missing \"{$needle}\"");
                    $ok = false;
                }
            }
            foreach ($check['reject'] as $needle) {
                if (str_contains($html, $needle)) {
                    $this->error("{$check['name']}: should not contain \"{$needle}\"");
                    $ok = false;
                }
            }

            if ($ok) {
                $this->info("{$check['name']}: OK");
            } else {
                $failed = true;
            }
        }

        if ($failed) {
            $this->line('');
            $this->warn('If Width is missing, push ovs-shopify-theme changes to the live Rise theme.');
            $this->warn('Optional later: Shopify Admin → Search & Discovery → add Product tag filter for native facet parity.');

            return self::FAILURE;
        }

        $this->info('Pilot storefront filters verified.');

        return self::SUCCESS;
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(25)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
