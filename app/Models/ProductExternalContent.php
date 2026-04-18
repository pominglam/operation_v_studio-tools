<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $source
 * @property string|null $source_url
 * @property string|null $title
 * @property string|null $description_html
 * @property array<string, string>|null $attributes_json
 */
final class ProductExternalContent extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'product_id',
        'source',
        'source_url',
        'title',
        'description_html',
        'attributes_json',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'attributes_json' => 'array',
    ];

    /** @return BelongsTo<Product, ProductExternalContent> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
