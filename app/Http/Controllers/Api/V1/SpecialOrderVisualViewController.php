<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SpecialOrders\SpecialOrderQueryService;
use App\Services\SpecialOrders\SpecialOrderVisualUploadService;
use App\Support\SpecialOrders\SpecialOrderVisualKind;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SpecialOrderVisualViewController extends Controller
{
    public function __construct(
        private readonly SpecialOrderQueryService $orders,
        private readonly SpecialOrderVisualUploadService $visuals,
    ) {}

    public function __invoke(string $id, string $kind): BinaryFileResponse
    {
        if (SpecialOrderVisualKind::normalize($kind) === null) {
            abort(404);
        }

        $order = $this->orders->findByUuidOrFail($id);
        $path = $this->visuals->resolveAbsolutePath($order, $kind);
        if ($path === null) {
            abort(404);
        }

        $mime = SpecialOrderVisualKind::mimeOn($order, $kind) ?? 'application/octet-stream';
        $filename = SpecialOrderVisualKind::filenameOn($order, $kind) ?? $kind;

        return response()->file($path, [
            'Content-Type' => is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
