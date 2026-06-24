<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shopify\Admin\Write;

use App\Services\Shopify\Admin\Write\ShopifyStorefrontNavCutoverService;
use App\Services\Shopify\Admin\Write\ShopifyWriteScopeGuard;
use Tests\TestCase;

final class ShopifyStorefrontNavCutoverServiceTest extends TestCase
{
    public function test_build_cutover_top_level_items_inserts_tools_and_supplies_between_model_kits_and_miscellaneous(): void
    {
        $service = new ShopifyStorefrontNavCutoverService(
            $this->createMock(\App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface::class),
            new ShopifyWriteScopeGuard,
        );

        $gids = [
            'tools-and-supplies' => 'gid://shopify/Collection/1',
            'brushes' => 'gid://shopify/Collection/2',
            'drills' => 'gid://shopify/Collection/3',
            'tweezers' => 'gid://shopify/Collection/4',
            'scribing-tools' => 'gid://shopify/Collection/5',
            'adhesives' => 'gid://shopify/Collection/6',
            'nippers-and-knives' => 'gid://shopify/Collection/7',
            'sanding' => 'gid://shopify/Collection/8',
            'tapes' => 'gid://shopify/Collection/9',
            'markers' => 'gid://shopify/Collection/10',
            'paints' => 'gid://shopify/Collection/11',
            'panel-liners' => 'gid://shopify/Collection/15',
            'decals' => 'gid://shopify/Collection/12',
            'airbrush' => 'gid://shopify/Collection/13',
            'workshop-misc' => 'gid://shopify/Collection/14',
        ];

        $items = $service->buildCutoverTopLevelItems([
            [
                'id' => 'gid://shopify/MenuItem/100',
                'title' => 'Model kits',
                'type' => 'HTTP',
                'url' => '#',
                'items' => [],
            ],
            [
                'id' => 'gid://shopify/MenuItem/200',
                'title' => 'Paints & Markers',
                'type' => 'HTTP',
                'url' => '#',
                'items' => [],
            ],
            [
                'id' => 'gid://shopify/MenuItem/300',
                'title' => 'Tools',
                'type' => 'HTTP',
                'url' => '#',
                'items' => [],
            ],
            [
                'id' => 'gid://shopify/MenuItem/400',
                'title' => 'Miscellaneous',
                'type' => 'HTTP',
                'url' => '#',
                'items' => [],
            ],
        ], $gids);

        $this->assertCount(3, $items);
        $this->assertSame('Model kits', $items[0]['title']);
        $this->assertSame('Tools & Supplies', $items[1]['title']);
        $this->assertSame('Miscellaneous', $items[2]['title']);
        $this->assertCount(15, $items[1]['items']);
        $this->assertSame('Adhesives', $items[1]['items'][0]['title']);
        $this->assertSame('All tools & supplies', $items[1]['items'][13]['title']);
        $this->assertSame('Other', $items[1]['items'][14]['title']);
        $this->assertSame('gid://shopify/Collection/6', $items[1]['items'][0]['resourceId']);
    }
}
