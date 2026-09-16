<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property array<string, mixed> $snapshot
 * @property list<string> $visible_columns
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class ProductSavedView extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'name',
        'snapshot',
        'visible_columns',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'visible_columns' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $view): void {
            if (($view->uuid ?? '') === '') {
                $view->uuid = (string) Str::uuid();
            }
        });
    }
}
