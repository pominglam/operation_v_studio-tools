<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Admin\Auth\ShopifyOAuthCallbackProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ShopifyOAuthCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        ShopifyOAuthCallbackProcessor $processor,
    ): RedirectResponse {
        return $processor->processCallback($request);
    }
}
