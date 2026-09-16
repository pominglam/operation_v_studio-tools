<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Support\SpecialOrders\SpecialOrderVisualKind;
use Illuminate\Support\Facades\Storage;

final class SpecialOrderDeleteService
{
    public function __construct(
        private readonly SpecialOrderRepository $orders,
    ) {}

    public function delete(string $uuid): void
    {
        $order = $this->orders->findByUuidOrFail($uuid);
        $disk = Storage::disk('local');

        foreach (SpecialOrderVisualKind::ALL as $kind) {
            $path = SpecialOrderVisualKind::pathOn($order, $kind);
            if ($path !== null && $disk->exists($path)) {
                $disk->delete($path);
            }
        }

        $this->orders->delete($order);
    }
}
