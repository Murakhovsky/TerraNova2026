<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit;

use InvalidArgumentException;
use Throwable;

final class BybitPerpetualTickerPayloadParser
{
    /** @return array<string,string> */
    public function parse(string $json,string $expectedSymbol):array
    {
        try{$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(Throwable){throw new InvalidArgumentException('Bybit perpetual ticker response is not valid JSON.');}
        if(!is_array($payload)||array_is_list($payload)||(string)($payload['retCode']??'')!=='0'){
            throw new InvalidArgumentException('Bybit perpetual ticker response is invalid.');
        }
        $rows=is_array($payload['result']??null)?($payload['result']['list']??null):null;
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('Bybit perpetual ticker list is missing.');
        $expected=strtoupper($expectedSymbol);
        foreach($rows as $row){
            if(!is_array($row)||array_is_list($row)||strtoupper((string)($row['symbol']??''))!==$expected)continue;
            return [
                'symbol'=>$expected,
                'bid1Price'=>$this->positive($row['bid1Price']??null,'bid1Price'),
                'bid1Size'=>$this->positive($row['bid1Size']??null,'bid1Size'),
                'ask1Price'=>$this->positive($row['ask1Price']??null,'ask1Price'),
                'ask1Size'=>$this->positive($row['ask1Size']??null,'ask1Size'),
                'volume24h'=>$this->nonNegative($row['volume24h']??null,'volume24h'),
                'markPrice'=>$this->positive($row['markPrice']??null,'markPrice'),
                'indexPrice'=>$this->positive($row['indexPrice']??null,'indexPrice'),
                'openInterest'=>$this->nonNegative($row['openInterest']??null,'openInterest'),
                'fundingRate'=>$this->signed($row['fundingRate']??null,'fundingRate'),
                'nextFundingTime'=>$this->integer($row['nextFundingTime']??null,'nextFundingTime'),
                'time'=>$this->integer($payload['time']??null,'time'),
            ];
        }
        throw new InvalidArgumentException('Bybit perpetual ticker did not contain the requested symbol.');
    }

    private function positive(mixed $v,string $f):string
    {
        $v=$this->signed($v,$f);
        if(preg_match('/^-?0+(?:\.0+)?$/',$v)===1||str_starts_with($v,'-'))throw new InvalidArgumentException('Bybit '.$f.' must be positive.');
        return $v;
    }
    private function nonNegative(mixed $v,string $f):string
    {
        $v=$this->signed($v,$f);
        if(str_starts_with($v,'-'))throw new InvalidArgumentException('Bybit '.$f.' cannot be negative.');
        return $v;
    }
    private function signed(mixed $v,string $f):string
    {
        if(!is_string($v)||preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/',$v)!==1)throw new InvalidArgumentException('Bybit '.$f.' must be a decimal string.');
        return $v;
    }
    private function integer(mixed $v,string $f):string
    {
        if((!is_string($v)&&!is_int($v))||preg_match('/^[0-9]+$/',(string)$v)!==1)throw new InvalidArgumentException('Bybit '.$f.' must be an unsigned integer.');
        return (string)$v;
    }
}
