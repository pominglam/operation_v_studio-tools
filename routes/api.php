<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ProductImportController;
use App\Http\Controllers\Api\V1\ProductBulkDeleteController;
use App\Http\Controllers\Api\V1\ProductMaintenanceController;
use App\Http\Controllers\Api\V1\ProductsController;
use App\Http\Controllers\Api\V1\PriceResearchProductsController;
use App\Http\Controllers\Api\V1\PriceResearchRunController;
use App\Http\Controllers\Api\V1\PriceResearchRunStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->group(function (): void {
        Route::get('/products', [ProductsController::class, 'index']);
        Route::post('/products', [ProductsController::class, 'store']);
        Route::patch('/products/{id}', [ProductsController::class, 'update'])->whereUuid('id');
        Route::post('/products/import', ProductImportController::class);
        Route::post('/products/bulk-delete', ProductBulkDeleteController::class);
        Route::delete('/products', [ProductMaintenanceController::class, 'flush']);

        Route::get('/price-research/products', PriceResearchProductsController::class);
        Route::post('/price-research/run', PriceResearchRunController::class);
        Route::get('/price-research/runs/latest', [PriceResearchRunStatusController::class, 'latest']);
        Route::get('/price-research/runs/{id}', [PriceResearchRunStatusController::class, 'show'])->whereUuid('id');
    });


