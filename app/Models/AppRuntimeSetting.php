<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
final class AppRuntimeSetting extends Model
{
    protected $table = 'app_runtime_settings';

    /** @var array<int, string> */
    protected $fillable = [
        'key',
        'value',
    ];
}
