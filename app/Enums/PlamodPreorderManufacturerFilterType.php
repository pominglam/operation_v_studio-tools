<?php

declare(strict_types=1);

namespace App\Enums;

enum PlamodPreorderManufacturerFilterType: string
{
    case Series = 'series';

    case CategoryLine = 'category_line';
}
