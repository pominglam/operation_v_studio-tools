<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\DAL\CustomOrders\CustomAsiaOrderRepository;
use App\Models\CustomAsiaOrder;
use App\Support\CustomOrders\CustomAsiaOrderContactMedia;
use App\Support\CustomOrders\CustomAsiaOrderReceiveDelayUnit;

final class CustomAsiaOrderCreateService
{
    public function __construct(
        private readonly CustomAsiaOrderRepository $orders,
    ) {}

    /**
     * @param  array{
     *   customer_contact_media: string,
     *   customer_contact_value: string,
     *   product_name: string,
     *   notes?: string|null
     * }  $input
     */
    public function create(array $input): CustomAsiaOrder
    {
        $media = CustomAsiaOrderContactMedia::normalize($input['customer_contact_media']);
        if ($media === null) {
            throw new \InvalidArgumentException('Invalid customer contact media.');
        }

        $value = trim($input['customer_contact_value']);
        if ($value === '') {
            throw new \InvalidArgumentException('Customer contact is required.');
        }

        $productName = trim($input['product_name']);
        if ($productName === '') {
            throw new \InvalidArgumentException('Product name is required.');
        }

        return $this->orders->create([
            'customer_contact_media' => $media,
            'customer_contact_value' => $value,
            'product_name' => $productName,
            'notes' => isset($input['notes']) ? trim((string) $input['notes']) : null,
            'receive_delay_amount' => CustomAsiaOrderReceiveDelayUnit::DEFAULT_AMOUNT,
            'receive_delay_unit' => CustomAsiaOrderReceiveDelayUnit::DEFAULT_UNIT,
            'receive_delay_days' => CustomAsiaOrderReceiveDelayUnit::defaultDays(),
        ]);
    }
}
