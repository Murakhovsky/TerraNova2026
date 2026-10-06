<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit;

use InvalidArgumentException;
use Throwable;

final class BybitPerpetualInstrumentPayloadParser
{
    /** @return array<string,mixed> */
    public function parse(string $json,string $expectedSymbol):array
    {
        try{$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(Throwable){throw new InvalidArgumentException('Bybit instrument response is not valid JSON.');}
        if(!is_array($payload)||array_is_list($payload)||(string)($payload['retCode']??'')!=='0')throw new InvalidArgumentException('Bybit instrument response is invalid.');
        $rows=is_array($payload['result']??null)?($payload['result']['list']??null):null;
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('Bybit instrument list is missing.');
        $expected=strtoupper($expectedSymbol);
        foreach($rows as $row){
            if(!is_array($row)||array_is_list($row)||strtoupper((string)($row['symbol']??''))!==$expected)continue;
            $lot=$row['lotSizeFilter']??null;$lev=$row['leverageFilter']??null;$price=$row['priceFilter']??null;
            if(!is_array($lot)||!is_array($lev)||!is_array($price))throw new InvalidArgumentException('Bybit instrument filters are missing.');
            $fundingInterval=$row['fundingInterval']??null;
            if(!is_int($fundingInterval)&&(!is_string($fundingInterval)||!ctype_digit($fundingInterval)))throw new InvalidArgumentException('Bybit funding interval is invalid.');
            return [
                'symbol'=>$expected,
                'contract_type'=>(string)($row['contractType']??''),
                'status'=>(string)($row['status']??''),
                'base_coin'=>(string)($row['baseCoin']??''),
                'quote_coin'=>(string)($row['quoteCoin']??''),
                'settle_coin'=>(string)($row['settleCoin']??''),
                'funding_interval_minutes'=>(int)$fundingInterval,
                'upper_funding_rate'=>$this->decimal((string)($row['upperFundingRate']??'0'),'upperFundingRate'),
                'lower_funding_rate'=>$this->decimal((string)($row['lowerFundingRate']??'0'),'lowerFundingRate'),
                'max_leverage'=>$this->positive((string)($lev['maxLeverage']??''),'maxLeverage'),
                'min_quantity'=>$this->positive((string)($lot['minOrderQty']??''),'minOrderQty'),
                'minimum_notional'=>$this->nonNegative((string)($lot['minNotionalValue']??'0'),'minNotionalValue'),
                'tick_size'=>$this->positive((string)($price['tickSize']??''),'tickSize'),
                'quantity_step'=>$this->positive((string)($lot['qtyStep']??''),'qtyStep'),
            ];
        }
        throw new InvalidArgumentException('Bybit instrument response did not contain the requested symbol.');
    }

    public function precision(string $step):int
    {
        $canonical=rtrim($step,'0');
        $dot=strpos($canonical,'.');
        return $dot===false?0:strlen($canonical)-$dot-1;
    }
    private function positive(string $v,string $f):string
    {
        $v=$this->decimal($v,$f);
        if(str_starts_with($v,'-')||preg_match('/^0+(?:\.0+)?$/',$v)===1)throw new InvalidArgumentException('Bybit '.$f.' must be positive.');
        return $v;
    }
    private function nonNegative(string $v,string $f):string
    {
        $v=$this->decimal($v,$f);
        if(str_starts_with($v,'-'))throw new InvalidArgumentException('Bybit '.$f.' cannot be negative.');
        return $v;
    }
    private function decimal(string $v,string $f):string
    {
        if(preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/',$v)!==1)throw new InvalidArgumentException('Bybit '.$f.' must be decimal.');
        return $v;
    }
}
