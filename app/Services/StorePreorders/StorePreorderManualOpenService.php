<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductRepository;
use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\Products\ProductSellingPriceUpsertContext;
use App\DTOs\StorePreorders\StorePreorderOpenResult;
use App\Models\Product;
use App\Models\StorePreorder;
use App\Services\Products\Exceptions\DuplicateSkuException;
use App\Services\Products\ProductCreateService;
use App\Services\Products\ProductManualImageUploadService;
use App\Services\Products\ProductPreferredDescriptionSourceService;
use App\Services\Products\ProductSellingPriceService;
use App\Services\StorePreorders\Exceptions\StorePreorderOpenException;
use App\Services\StorePreorders\Listing\StorePreorderListingPhotoStagingService;
use App\Support\Pricing\OpvStandardCatalogPrice;
use App\Support\StorePreorders\StorePreorderClosingDate;
use App\Support\StorePreorders\StorePreorderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StorePreorderManualOpenService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly ProductRepository $products,
        private readonly ProductCreateService $create,
        private readonly ProductSellingPriceService $sellingPrices,
        private readonly ProductPreferredDescriptionSourceService $descriptions,
        private readonly ProductExternalAssetRepository $assets,
        private readonly StorePreorderListingPhotoStagingService $staging,
        private readonly StorePreorderOpenPublishService $publish,
    ) {}

    /**
     * @param  array{sku: string, product_name: string, description_html: string|null, selling_price: string, deposit_percent: string, cap_qty: int|null, window_ends_on: string|null, eta_date: string|null, photo_ids: list<string>}  $input
     */
    public function open(array $input): StorePreorderOpenResult
    {
        $normalized = $this->normalize($input);
        $result = DB::transaction(function () use ($normalized): StorePreorderOpenResult {
            $this->assertSkuAvailable($normalized['sku']);
            $product = $this->createProduct($normalized);
            $this->writeSellingPrice($product, $normalized['selling_price']);
            $this->writeDescription($product, $normalized['description_html']);
            $this->attachPhotos($product, $normalized['photo_ids']);
            $offer = $this->createOffer($product, $normalized);

            return new StorePreorderOpenResult(collect([$offer]), 1, 0, 0, 1);
        });

        return $this->publish->afterManualOpen($result);
    }

    /**
     * @param  array{sku: string, product_name: string, description_html: string|null, selling_price: string, deposit_percent: string, cap_qty: int|null, window_ends_on: ?Carbon, eta_date: ?Carbon, photo_ids: list<string>}  $input
     */
    private function createProduct(array $input): Product
    {
        $product = $this->create->create([
            'sku' => $input['sku'],
            'description' => $input['product_name'],
            'department' => 'model kits',
            'main_type' => 'model kit',
            'available' => 0,
        ]);
        $product->published_on_shopify = true;
        $this->products->save($product);

        return $product;
    }

    private function writeSellingPrice(Product $product, string $price): void
    {
        $this->sellingPrices->upsertForProductUuid(
            $product->uuid,
            $price,
            'CAD',
            new ProductSellingPriceUpsertContext('store_preorder'),
        );
    }

    private function writeDescription(Product $product, ?string $html): void
    {
        if ($html === null) {
            return;
        }

        $this->descriptions->setForProduct($product->uuid, 'other', $html);
    }

    /**
     * @param  list<string>  $photoIds
     */
    private function attachPhotos(Product $product, array $photoIds): void
    {
        $rows = [];
        $order = 0;
        foreach ($photoIds as $id) {
            $staged = $this->staging->read($id);
            if ($staged === null) {
                continue;
            }
            $order++;
            $rows[] = $this->copyStagedPhoto($product, $staged, $order);
        }
        if ($rows !== []) {
            $this->assets->createForProduct((int) $product->id, ProductManualImageUploadService::SOURCE, $rows);
        }
        $this->staging->forget($photoIds);
    }

    /**
     * @param  array{path: string, mime: string, filename: string}  $staged
     * @return array<string, mixed>
     */
    private function copyStagedPhoto(Product $product, array $staged, int $order): array
    {
        $dir = 'manual_upload/images/'.$product->uuid;
        $storageName = (string) Str::uuid().'-'.$staged['filename'];
        $storagePath = $dir.'/'.$storageName;
        Storage::disk('local')->put($storagePath, (string) file_get_contents($staged['path']));
        $abs = Storage::disk('local')->path($storagePath);
        $sha = is_file($abs) ? hash_file('sha256', $abs) : null;

        return [
            'kind' => 'image',
            'storage_path' => $storagePath,
            'filename' => $staged['filename'],
            'mime_type' => $staged['mime'],
            'size_bytes' => is_file($abs) ? filesize($abs) : null,
            'checksum_sha256' => is_string($sha) && $sha !== '' ? $sha : null,
            'sort_order' => $order,
            'shopify_enabled' => true,
        ];
    }

    /**
     * @param  array{sku: string, product_name: string, description_html: string|null, selling_price: string, deposit_percent: string, cap_qty: int|null, window_ends_on: ?Carbon, eta_date: ?Carbon, photo_ids: list<string>}  $input
     */
    private function createOffer(Product $product, array $input): StorePreorder
    {
        return $this->offers->create([
            'product_id' => $product->id,
            'plamod_sku' => $input['sku'],
            'status' => StorePreorderStatus::OPEN,
            'deposit_percent' => $input['deposit_percent'],
            'cap_qty' => $input['cap_qty'],
            'selling_price_cad' => $input['selling_price'],
            'po_cost_cad' => null,
            'window_ends_on' => $input['window_ends_on'],
            'eta_date' => $input['eta_date'],
            'opened_at' => now(),
            'closed_at' => null,
        ]);
    }

    private function assertSkuAvailable(string $sku): void
    {
        if ($this->products->findBySkus([$sku])->isNotEmpty()) {
            throw new DuplicateSkuException('SKU already exists.');
        }
        if ($this->offers->findByPlamodSku($sku) instanceof StorePreorder) {
            throw new StorePreorderOpenException('A store preorder already uses this SKU.');
        }
    }

    /**
     * @param  array{sku: string, product_name: string, description_html: string|null, selling_price: string, deposit_percent: string, cap_qty: int|null, window_ends_on: string|null, eta_date: string|null, photo_ids: list<string>}  $input
     * @return array{sku: string, product_name: string, description_html: string|null, selling_price: string, deposit_percent: string, cap_qty: int|null, window_ends_on: ?Carbon, eta_date: ?Carbon, photo_ids: list<string>}
     */
    private function normalize(array $input): array
    {
        $sku = trim($input['sku']);
        $name = trim($input['product_name']);
        if (strlen($name) > 512) {
            $name = substr($name, 0, 512);
        }
        $price = OpvStandardCatalogPrice::fromEnteredPrice($input['selling_price']);
        if ($sku === '' || $name === '' || $price === null) {
            throw new StorePreorderOpenException('SKU, product name, and sell $ are required.');
        }

        return [
            'sku' => $sku,
            'product_name' => $name,
            'description_html' => $this->nullableTrim($input['description_html']),
            'selling_price' => $price,
            'deposit_percent' => $this->normalizeDeposit($input['deposit_percent']),
            'cap_qty' => $input['cap_qty'],
            'window_ends_on' => $this->normalizeDay($input['window_ends_on'], 'Closing date is invalid.'),
            'eta_date' => $this->normalizeDay($input['eta_date'], 'ETA is invalid.'),
            'photo_ids' => $input['photo_ids'],
        ];
    }

    private function normalizeDeposit(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            throw new StorePreorderOpenException('Deposit percent is required.');
        }
        $amount = (float) $trimmed;
        if ($amount < 1 || $amount > 100) {
            throw new StorePreorderOpenException('Deposit percent must be between 1 and 100.');
        }

        return number_format($amount, 2, '.', '');
    }

    private function normalizeDay(?string $value, string $error): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $day = StorePreorderClosingDate::parseDay($value);
        if ($day === null) {
            throw new StorePreorderOpenException($error);
        }

        return $day;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
