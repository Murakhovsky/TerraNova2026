<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketConnectionState:string
{
    case Disconnected='DISCONNECTED';
    case Connecting='CONNECTING';
    case Connected='CONNECTED';
    case Subscribing='SUBSCRIBING';
    case Active='ACTIVE';
    case Degraded='DEGRADED';
    case Reconnecting='RECONNECTING';
    case Failed='FAILED';
    case Disabled='DISABLED';
}
