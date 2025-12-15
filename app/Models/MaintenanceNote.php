<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string|null $body
 */
final class MaintenanceNote extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'key',
        'body',
    ];
}
