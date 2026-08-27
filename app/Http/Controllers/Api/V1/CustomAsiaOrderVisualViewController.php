<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CustomOrders\CustomAsiaOrderQueryService;
use App\Services\CustomOrders\CustomAsiaOrderVisualUploadService;
use App\Support\CustomOrders\CustomAsiaOrderVisualKind;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CustomAsiaOrderVisualViewController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderQueryService $orders,
        private readonly CustomAsiaOrderVisualUploadService $visuals,
    ) {}

    public function __invoke(string $id, string $kind): BinaryFileResponse
    {
        if (CustomAsiaOrderVisualKind::normalize($kind) === null) {
            abort(404);
        }

        $order = $this->orders->findByUuidOrFail($id);
        $path = $this->visuals->resolveAbsolutePath($order, $kind);
        if ($path === null) {
            abort(404);
        }

        $mime = CustomAsiaOrderVisualKind::mimeOn($order, $kind) ?? 'application/octet-stream';
        $filename = CustomAsiaOrderVisualKind::filenameOn($order, $kind) ?? $kind;

        return response()->file($path, [
            'Content-Type' => is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
