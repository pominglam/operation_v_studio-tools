<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductsQueryService;
use Illuminate\Http\JsonResponse;

final class ProductFilterOptionsController extends Controller
{
    public function __construct(
        private readonly ProductsQueryService $products,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'main_types' => $this->products->distinctMainTypes(),
                'types' => $this->products->distinctTypes(),
                'departments' => $this->products->distinctDepartments(),
                'manufacturers' => $this->products->distinctManufacturers(),
                'franchises' => $this->products->distinctFranchises(),
                'product_lines' => $this->products->distinctProductLines(),
                'sublines' => $this->products->distinctSublines(),
                'vendors' => $this->products->distinctVendors(),
                'grades' => $this->products->distinctGrades(),
                'scales' => $this->products->distinctScales(),
                'series' => $this->products->distinctSeries(),
            ],
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
