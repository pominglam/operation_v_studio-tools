<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property \Illuminate\Support\Carbon $happened_on
 * @property string|null $notes
 */
final class StoreMarketingNote extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'name',
        'happened_on',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'happened_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $note): void {
            if (($note->uuid ?? '') === '') {
                $note->uuid = (string) Str::uuid();
            }
        });
    }
}
