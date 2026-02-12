<?php

use Illuminate\Support\Facades\Route;

Route::get('/external-login', [\App\Http\Controllers\ExternalLoginController::class, 'show']);
Route::post('/external-login', [\App\Http\Controllers\ExternalLoginController::class, 'submit'])
    // Minimal external password gate (no Laravel session auth). Avoid CSRF token complexity here.
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);

Route::get('/shopify-images/{id}', \App\Http\Controllers\ShopifyImageController::class)
    ->whereNumber('id')
    // Shopify fetches these as raw assets; avoid cookies/sessions/CSRF so responses are cacheable and stable.
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('shopify-images');

// Path-signed variant (Shopify CSV imports may strip query parameters).
Route::get('/shopify-images/{id}/{expires}/{signature}', \App\Http\Controllers\ShopifyImageController::class)
    ->whereNumber('id')
    ->whereNumber('expires')
    ->where('signature', '[a-f0-9]{64}')
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('shopify-images-path');

Route::get('/shopify-images/{id}/{expires}/{signature}/{filename}', \App\Http\Controllers\ShopifyImageController::class)
    ->whereNumber('id')
    ->whereNumber('expires')
    ->where('signature', '[a-f0-9]{64}')
    // Keep filename conservative (no slashes). This is for Shopify compatibility / readability only.
    ->where('filename', '[^/]+')
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('shopify-images-path-filename');

Route::get('/', function () {
    return view('app');
});

Route::view('/{any}', 'app')->where('any', '.*');
