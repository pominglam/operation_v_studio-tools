<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $name
 * @property string|null $source
 * @property string|null $uploaded_file_path
 * @property string|null $notes
 * @property string $workflow_state
 * @property string|null $created_by_role
 * @property \Illuminate\Support\Carbon|null $applied_at
 */
final class InventoryCheck extends Model
{
    protected $table = 'inventory_check';

    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'name',
        'source',
        'uploaded_file_path',
        'notes',
        'workflow_state',
        'created_by_role',
        'applied_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'applied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $check): void {
            if (($check->uuid ?? '') === '') {
                $check->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return HasMany<InventoryCheckItem> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryCheckItem::class, 'inventory_check_id');
    }
}
