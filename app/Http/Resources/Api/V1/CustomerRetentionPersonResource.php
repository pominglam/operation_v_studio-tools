<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\Customers\CustomerRetentionPerson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<CustomerRetentionPerson> */
final class CustomerRetentionPersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerRetentionPerson $person */
        $person = $this->resource;

        return [
            'id' => $person->id,
            'display_name' => $person->displayName,
            'frequency_label' => $person->frequencyLabel,
            'is_repeat' => $person->isRepeat,
            'rfm_group' => $person->rfmGroup,
            'rfm_group_name' => $person->rfmGroupName,
            'recency_score' => $person->recencyScore,
            'frequency_score' => $person->frequencyScore,
            'monetary_score' => $person->monetaryScore,
            'fm_score' => $person->fmScore,
            'order_count' => $person->orderCount,
            'spend' => $person->spend,
            'aov' => $person->aov,
            'cadence' => $person->cadence?->toArray(),
            'churn' => $person->churn?->toArray(),
            'last_order_at' => $person->lastOrderAtIso,
            'shopify_admin_url' => $person->shopifyAdminUrl,
        ];
    }
}
