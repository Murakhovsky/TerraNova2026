<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketSequencePolicy:string
{
    case None='NONE';
    case Monotonic='MONOTONIC';
    case Contiguous='CONTIGUOUS';
}
