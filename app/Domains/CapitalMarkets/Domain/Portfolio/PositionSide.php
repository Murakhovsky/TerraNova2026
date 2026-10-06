<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
enum PositionSide:string
{
    case Long='LONG';
    case Short='SHORT';
    public function sign():int{return $this===self::Long?1:-1;}
}
