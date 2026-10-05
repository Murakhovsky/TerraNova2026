<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum SequenceSemantics:string
{
    case None='NONE';
    case Monotonic='MONOTONIC';
    case Contiguous='CONTIGUOUS';
}
