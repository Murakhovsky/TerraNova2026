<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;

final class VenueMarketStatusResolver
{
    public function resolve(?VenueInstrument $mapping,DateTimeImmutable $at):MarketStatus
    {
        if($mapping===null)return MarketStatus::Unknown;
        if($mapping->status!==VenueInstrumentStatus::Active)return MarketStatus::Closed;
        $meta=$mapping->metadata;
        if(($meta['always_open']??false)===true)return MarketStatus::Open;
        $hours=$meta['market_hours']??null;
        $timezone=$meta['market_hours_timezone']??null;
        if(!is_array($hours)||!is_string($timezone)||$timezone==='')return MarketStatus::Unknown;
        try{$local=$at->setTimezone(new DateTimeZone($timezone));}
        catch(\Throwable){return MarketStatus::Unknown;}
        $day=(int)$local->format('N');
        $minute=((int)$local->format('H'))*60+(int)$local->format('i');
        foreach($hours as $window){
            if(!is_array($window))continue;
            $days=$window['days']??[];
            if(!is_array($days)||!in_array($day,array_map('intval',$days),true))continue;
            $open=$this->minute($window['open']??null);
            $close=$this->minute($window['close']??null,true);
            if($open===null||$close===null)continue;
            if($open===$close)return MarketStatus::Open;
            if($open<$close&&$minute>=$open&&$minute<$close)return MarketStatus::Open;
            if($open>$close&&($minute>=$open||$minute<$close))return MarketStatus::Open;
        }
        return MarketStatus::Closed;
    }

    private function minute(mixed $value,bool $allow24=false):?int
    {
        if(!is_string($value)||preg_match('/^(\d{2}):(\d{2})$/',$value,$m)!==1)return null;
        $h=(int)$m[1];$min=(int)$m[2];
        if($allow24&&$h===24&&$min===0)return 1440;
        if($h>23||$min>59)return null;
        return $h*60+$min;
    }
}
