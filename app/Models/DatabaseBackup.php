<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $driver
 * @property string $filename
 * @property string $storage_path
 * @property string $description
 * @property string $created_by
 * @property int|null $size_bytes
 */
final class DatabaseBackup extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'driver',
        'filename',
        'storage_path',
        'description',
        'created_by',
        'size_bytes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $backup): void {
            if (($backup->uuid ?? '') === '') {
                $backup->uuid = (string) Str::uuid();
            }
        });
    }
}
