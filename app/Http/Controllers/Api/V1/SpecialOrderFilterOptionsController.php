<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\SpecialOrders\SpecialOrderContactMedia;
use App\Support\SpecialOrders\SpecialOrderCurrency;
use App\Support\SpecialOrders\SpecialOrderLifecycleStatus;
use App\Support\SpecialOrders\SpecialOrderReceiveDelayUnit;
use Illuminate\Http\JsonResponse;

final class SpecialOrderFilterOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'contact_media' => array_map(
                    static fn (string $value): array => [
                        'value' => $value,
                        'label' => SpecialOrderContactMedia::label($value),
                    ],
                    SpecialOrderContactMedia::ALL,
                ),
                'currencies' => array_map(
                    static fn (string $value): array => [
                        'value' => $value,
                        'label' => SpecialOrderCurrency::label($value),
                    ],
                    SpecialOrderCurrency::ALL,
                ),
                'receive_delay_units' => array_map(
                    static fn (string $value): array => [
                        'value' => $value,
                        'label' => SpecialOrderReceiveDelayUnit::label($value),
                    ],
                    SpecialOrderReceiveDelayUnit::ALL,
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
                    ['value' => SpecialOrderLifecycleStatus::ACTIVE, 'label' => 'Active'],
                    ['value' => SpecialOrderLifecycleStatus::CONSIDERING, 'label' => 'Customer thinking'],
                    ['value' => SpecialOrderLifecycleStatus::REJECTED, 'label' => 'Rejected'],
                    ['value' => SpecialOrderLifecycleStatus::ALL, 'label' => 'All'],
                ],
            ],
        ]);
    }
}
