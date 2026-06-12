<?php

declare(strict_types=1);

namespace App\Enums;

enum PlamodPreorderManufacturerFilterDecision: string
{
    case Undecided = 'undecided';

    case Include = 'include';

    case Exclude = 'exclude';
}
