<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Auth\ShopifyOauthRedirectUriResolver;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shopify:oauth:url')]
final class ShopifyOAuthInstallUrlCommand extends Command
{
    protected $description = 'Print Shopify OAuth URLs (Dev Dashboard client credentials flow).';

    public function handle(): int
    {
        try {
            $this->line('BEGIN (browser): '.route('shopify.oauth.install'));
        } catch (\Throwable) {
            $this->line('BEGIN (fallback): '.rtrim((string) config('app.url'), '/').'/shopify/oauth/install');
        }

        try {
            $this->line('CALLBACK must match Dev Dashboard Allowed redirection URLs: '.ShopifyOauthRedirectUriResolver::callbackUrlResolved());
        } catch (\Throwable $e) {
            $this->warn('Callback URL unresolved: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
