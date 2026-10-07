<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

enum DatasetQualityStatus:string
{
    case Sufficient='SUFFICIENT';
    case Limited='LIMITED';
    case Unsuitable='UNSUITABLE';
}
