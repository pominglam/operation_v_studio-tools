<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\CustomOrders\CustomAsiaOrderContactMedia;
use App\Support\CustomOrders\CustomAsiaOrderCurrency;
use App\Support\CustomOrders\CustomAsiaOrderLifecycleStatus;
use App\Support\CustomOrders\CustomAsiaOrderReceiveDelayUnit;
use Illuminate\Http\JsonResponse;

final class CustomAsiaOrderFilterOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'contact_media' => array_map(
                    static fn (string $value): array => [
                        'value' => $value,
                        'label' => CustomAsiaOrderContactMedia::label($value),
                    ],
                    CustomAsiaOrderContactMedia::ALL,
                ),
                'currencies' => array_map(
                    static fn (string $value): array => [
                        'value' => $value,
                        'label' => CustomAsiaOrderCurrency::label($value),
                    ],
                    CustomAsiaOrderCurrency::ALL,
                ),
                'receive_delay_units' => array_map(
                    static fn (string $value): array => [
                        'value' => $value,
                        'label' => CustomAsiaOrderReceiveDelayUnit::label($value),
                    ],
                    CustomAsiaOrderReceiveDelayUnit::ALL,
                ),
                'quote_statuses' => [
                    ['value' => 'pending', 'label' => 'Pending quote'],
                    ['value' => 'quoted', 'label' => 'Quoted'],
                ],
                'pricing_statuses' => [
                    ['value' => 'pending', 'label' => 'Pending pricing'],
                    ['value' => 'priced', 'label' => 'Price & deposit set'],
                ],
                'lifecycle_statuses' => [
                    ['value' => CustomAsiaOrderLifecycleStatus::ACTIVE, 'label' => 'Active'],
                    ['value' => CustomAsiaOrderLifecycleStatus::REJECTED, 'label' => 'Rejected'],
                    ['value' => CustomAsiaOrderLifecycleStatus::ALL, 'label' => 'All'],
                ],
            ],
        ]);
    }
}
