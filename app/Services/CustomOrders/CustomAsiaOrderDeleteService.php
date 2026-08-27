<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\DAL\CustomOrders\CustomAsiaOrderRepository;
use App\Support\CustomOrders\CustomAsiaOrderVisualKind;
use Illuminate\Support\Facades\Storage;

final class CustomAsiaOrderDeleteService
{
    public function __construct(
        private readonly CustomAsiaOrderRepository $orders,
    ) {}

    public function delete(string $uuid): void
    {
        $order = $this->orders->findByUuidOrFail($uuid);
        $disk = Storage::disk('local');

        foreach (CustomAsiaOrderVisualKind::ALL as $kind) {
            $path = CustomAsiaOrderVisualKind::pathOn($order, $kind);
            if ($path !== null && $disk->exists($path)) {
                $disk->delete($path);
            }
        }

        $this->orders->delete($order);
    }
}
