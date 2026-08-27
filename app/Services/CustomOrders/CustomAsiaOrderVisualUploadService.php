<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\DAL\CustomOrders\CustomAsiaOrderRepository;
use App\Models\CustomAsiaOrder;
use App\Support\CustomOrders\CustomAsiaOrderVisualKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CustomAsiaOrderVisualUploadService
{
    public const string KIND_CUSTOMER = CustomAsiaOrderVisualKind::CUSTOMER;

    public const string KIND_PRODUCT = CustomAsiaOrderVisualKind::PRODUCT;

    public const string KIND_MERCHANDISER_ORDER_PROOF = CustomAsiaOrderVisualKind::MERCHANDISER_ORDER_PROOF;

    public function __construct(
        private readonly CustomAsiaOrderRepository $orders,
    ) {}

    public function upload(string $orderUuid, string $kind, UploadedFile $file): CustomAsiaOrder
    {
        $kind = CustomAsiaOrderVisualKind::normalize($kind);
        if ($kind === null) {
            throw new \InvalidArgumentException('Invalid visual kind.');
        }

        $order = $this->orders->findByUuidOrFail($orderUuid);
        $disk = Storage::disk('local');
        $dir = 'custom_asia_orders/'.$order->uuid;
        $columns = CustomAsiaOrderVisualKind::columns($kind);

        $orig = trim((string) $file->getClientOriginalName());
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $ext = $ext !== '' ? $ext : 'jpg';
        $safeOrig = $orig !== '' ? basename(str_replace(['\\', '/'], '-', $orig)) : ($kind.'.'.$ext);
        $storageName = $kind.'-'.(string) Str::uuid().'-'.$safeOrig;

        $oldPath = CustomAsiaOrderVisualKind::pathOn($order, $kind);

        $storagePath = $disk->putFileAs($dir, $file, $storageName);
        $mime = $file->getMimeType() ?? 'application/octet-stream';

        $updated = $this->orders->update($order, [
            $columns['path'] => $storagePath,
            $columns['mime'] => is_string($mime) ? $mime : 'application/octet-stream',
            $columns['filename'] => $safeOrig,
        ]);

        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $storagePath && $disk->exists($oldPath)) {
            $disk->delete($oldPath);
        }

        return $updated;
    }

    public function resolveAbsolutePath(CustomAsiaOrder $order, string $kind): ?string
    {
        $kind = CustomAsiaOrderVisualKind::normalize($kind);
        if ($kind === null) {
            return null;
        }

        $path = CustomAsiaOrderVisualKind::pathOn($order, $kind);
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->path($path);
    }

    public function delete(string $orderUuid, string $kind): CustomAsiaOrder
    {
        $kind = CustomAsiaOrderVisualKind::normalize($kind);
        if ($kind === null) {
            throw new \InvalidArgumentException('Invalid visual kind.');
        }

        $order = $this->orders->findByUuidOrFail($orderUuid);
        $path = CustomAsiaOrderVisualKind::pathOn($order, $kind);
        $columns = CustomAsiaOrderVisualKind::columns($kind);

        if ($path !== null) {
            $disk = Storage::disk('local');
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }

        return $this->orders->update($order, [
            $columns['path'] => null,
            $columns['mime'] => null,
            $columns['filename'] => null,
        ]);
    }
}
