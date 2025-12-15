<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

final class PandaHobbyProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'panda_hobby';
    }

    public function siteName(): string
    {
        return config('price_research.sites.panda_hobby.name', 'Panda Hobby');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.panda_hobby.base_url', 'https://pandahobby.ca');
    }
}
