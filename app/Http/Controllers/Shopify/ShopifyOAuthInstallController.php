<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Admin\Auth\ShopifyOAuthCallbackProcessor;
use App\Services\Shopify\Admin\Auth\ShopifyOAuthInstallRedirectBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ShopifyOAuthInstallController extends Controller
{
    public function __invoke(
        Request $request,
        ShopifyOAuthInstallRedirectBuilder $redirectBuilder,
    ): RedirectResponse {
        $nonce = bin2hex(random_bytes(16));
        $request->session()->put(ShopifyOAuthCallbackProcessor::SESSION_STATE_KEY, $nonce);

        return redirect()->away($redirectBuilder->authorizationRedirectUrl($nonce));
    }
}
