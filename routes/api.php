<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PriceResearchProductsController;
use App\Http\Controllers\Api\V1\PriceResearchQuoteMaintenanceController;
use App\Http\Controllers\Api\V1\PriceResearchQuoteReportsController;
use App\Http\Controllers\Api\V1\PriceResearchRunController;
use App\Http\Controllers\Api\V1\PriceResearchRunLogsController;
use App\Http\Controllers\Api\V1\PriceResearchRunMaintenanceController;
use App\Http\Controllers\Api\V1\PriceResearchRunStatusController;
use App\Http\Controllers\Api\V1\ProductBulkDeleteController;
use App\Http\Controllers\Api\V1\ProductFilterOptionsController;
use App\Http\Controllers\Api\V1\ProductImportController;
use App\Http\Controllers\Api\V1\ProductMaintenanceController;
use App\Http\Controllers\Api\V1\ProductSellingPriceController;
use App\Http\Controllers\Api\V1\ProductsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->group(function (): void {
        Route::get('/products', [ProductsController::class, 'index']);
        Route::get('/products/filter-options', ProductFilterOptionsController::class);
        Route::post('/products', [ProductsController::class, 'store']);
        Route::patch('/products/{id}', [ProductsController::class, 'update'])->whereUuid('id');
        Route::put('/products/{id}/selling-price', ProductSellingPriceController::class)->whereUuid('id');
        Route::post('/products/import', ProductImportController::class);
        Route::post('/products/bulk-delete', ProductBulkDeleteController::class);
        Route::delete('/products', [ProductMaintenanceController::class, 'flush']);

        Route::get('/price-research/products', PriceResearchProductsController::class);
        Route::delete('/price-research/products/{id}/quotes/{siteKey}', PriceResearchQuoteMaintenanceController::class)->whereUuid('id');
        Route::get('/price-research/reports', [PriceResearchQuoteReportsController::class, 'index']);
        Route::post('/price-research/reports', [PriceResearchQuoteReportsController::class, 'store']);
        Route::post('/price-research/run', PriceResearchRunController::class);
        Route::post('/price-research/runs/reset', PriceResearchRunMaintenanceController::class);
        Route::get('/price-research/runs/latest', [PriceResearchRunStatusController::class, 'latest']);
        Route::get('/price-research/runs/{id}', [PriceResearchRunStatusController::class, 'show'])->whereUuid('id');
        Route::get('/price-research/runs/{id}/logs', PriceResearchRunLogsController::class)->whereUuid('id');
    });
