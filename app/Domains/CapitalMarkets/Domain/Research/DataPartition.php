<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

enum DataPartition:string
{
    case Train='TRAIN';
    case Validation='VALIDATION';
    case OutOfSample='OUT_OF_SAMPLE';
}
