<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $source
 * @property string $kind
 * @property string $storage_path
 * @property string $filename
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property int|null $sort_order
 */
final class ProductExternalAsset extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'product_id',
        'source',
        'kind',
        'storage_path',
        'filename',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'size_bytes' => 'integer',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<Product, ProductExternalAsset> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}


