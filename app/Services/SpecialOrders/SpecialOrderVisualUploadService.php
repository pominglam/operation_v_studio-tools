<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Models\SpecialOrder;
use App\Support\SpecialOrders\SpecialOrderVisualKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SpecialOrderVisualUploadService
{
    public const string KIND_CUSTOMER = SpecialOrderVisualKind::CUSTOMER;

    public const string KIND_PRODUCT = SpecialOrderVisualKind::PRODUCT;

    public const string KIND_MERCHANDISER_ORDER_PROOF = SpecialOrderVisualKind::MERCHANDISER_ORDER_PROOF;

    public function __construct(
        private readonly SpecialOrderRepository $orders,
    ) {}

    public function upload(string $orderUuid, string $kind, UploadedFile $file): SpecialOrder
    {
        $kind = SpecialOrderVisualKind::normalize($kind);
        if ($kind === null) {
            throw new \InvalidArgumentException('Invalid visual kind.');
        }

        $order = $this->orders->findByUuidOrFail($orderUuid);
        $disk = Storage::disk('local');
        $dir = 'special_orders/'.$order->uuid;
        $columns = SpecialOrderVisualKind::columns($kind);

        $orig = trim((string) $file->getClientOriginalName());
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $ext = $ext !== '' ? $ext : 'jpg';
        $safeOrig = $orig !== '' ? basename(str_replace(['\\', '/'], '-', $orig)) : ($kind.'.'.$ext);
        $storageName = $kind.'-'.(string) Str::uuid().'-'.$safeOrig;

        $oldPath = SpecialOrderVisualKind::pathOn($order, $kind);

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

    public function resolveAbsolutePath(SpecialOrder $order, string $kind): ?string
    {
        $kind = SpecialOrderVisualKind::normalize($kind);
        if ($kind === null) {
            return null;
        }

        $path = SpecialOrderVisualKind::pathOn($order, $kind);
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->path($path);
    }

    public function delete(string $orderUuid, string $kind): SpecialOrder
    {
        $kind = SpecialOrderVisualKind::normalize($kind);
        if ($kind === null) {
            throw new \InvalidArgumentException('Invalid visual kind.');
        }

        $order = $this->orders->findByUuidOrFail($orderUuid);
        $path = SpecialOrderVisualKind::pathOn($order, $kind);
        $columns = SpecialOrderVisualKind::columns($kind);

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
