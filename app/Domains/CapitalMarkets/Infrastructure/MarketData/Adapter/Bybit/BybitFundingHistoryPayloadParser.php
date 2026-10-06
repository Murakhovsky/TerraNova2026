<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit;

use InvalidArgumentException;
use Throwable;

final class BybitFundingHistoryPayloadParser
{
    /** @return list<array{symbol:string,rate:string,timestamp:string}> */
    public function parse(string $json,string $expectedSymbol):array
    {
        try{$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(Throwable){throw new InvalidArgumentException('Bybit funding history is not valid JSON.');}
        if(!is_array($payload)||array_is_list($payload)||(string)($payload['retCode']??'')!=='0')throw new InvalidArgumentException('Bybit funding history response is invalid.');
        $rows=is_array($payload['result']??null)?($payload['result']['list']??null):null;
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('Bybit funding history list is missing.');
        $out=[];$expected=strtoupper($expectedSymbol);
        foreach($rows as $row){
            if(!is_array($row)||array_is_list($row)||strtoupper((string)($row['symbol']??''))!==$expected)continue;
            $rate=(string)($row['fundingRate']??'');$ts=(string)($row['fundingRateTimestamp']??'');
            if(preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/',$rate)!==1||!ctype_digit($ts))throw new InvalidArgumentException('Bybit funding history row is invalid.');
            $out[]=['symbol'=>$expected,'rate'=>$rate,'timestamp'=>$ts];
        }
        return $out;
    }
}
