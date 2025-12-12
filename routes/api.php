<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ProductImportController;
use App\Http\Controllers\Api\V1\ProductMaintenanceController;
use App\Http\Controllers\Api\V1\ProductsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->group(function (): void {
        Route::get('/products', [ProductsController::class, 'index']);
        Route::post('/products/import', ProductImportController::class);
        Route::delete('/products', [ProductMaintenanceController::class, 'flush']);
    });


