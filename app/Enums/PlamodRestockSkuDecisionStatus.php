<?php

declare(strict_types=1);

namespace App\Enums;

enum PlamodRestockSkuDecisionStatus: string
{
    case Dismissed = 'dismissed';
    case Included = 'included';
    case Later = 'later';
}
