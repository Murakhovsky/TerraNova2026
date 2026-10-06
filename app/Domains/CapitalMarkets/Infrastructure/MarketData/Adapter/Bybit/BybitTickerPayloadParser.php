<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit;

use InvalidArgumentException;
use Throwable;

final class BybitTickerPayloadParser
{
    /** @return array{symbol:string,bid1Price:string,bid1Size:string,ask1Price:string,ask1Size:string,volume24h:string,time:string} */
    public function parse(string $json,string $expectedSymbol):array
    {
        try{$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(Throwable){throw new InvalidArgumentException('Bybit ticker response is not valid JSON.');}
        if(!is_array($payload)||array_is_list($payload))throw new InvalidArgumentException('Bybit ticker response must be an object.');
        if((string)($payload['retCode']??'')!=='0')throw new InvalidArgumentException('Bybit ticker response returned a non-zero retCode.');

        $result=$payload['result']??null;
        $rows=is_array($result)&&!array_is_list($result)?($result['list']??null):null;
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('Bybit ticker response list is missing.');

        $expected=strtoupper($expectedSymbol);
        foreach($rows as $row){
            if(!is_array($row)||array_is_list($row))continue;
            $symbol=strtoupper((string)($row['symbol']??''));
            if($symbol!==$expected)continue;
            return [
                'symbol'=>$symbol,
                'bid1Price'=>$this->decimalString($row['bid1Price']??null,'bid1Price'),
                'bid1Size'=>$this->decimalString($row['bid1Size']??null,'bid1Size'),
                'ask1Price'=>$this->decimalString($row['ask1Price']??null,'ask1Price'),
                'ask1Size'=>$this->decimalString($row['ask1Size']??null,'ask1Size'),
                'volume24h'=>$this->decimalString($row['volume24h']??null,'volume24h',true),
                'time'=>$this->integerString($payload['time']??null,'time'),
            ];
        }
        throw new InvalidArgumentException('Bybit ticker response did not contain the requested symbol.');
    }

    private function decimalString(mixed $value,string $field,bool $allowZero=false):string
    {
        if(!is_string($value)||preg_match('/^[0-9]+(?:\.[0-9]+)?$/',$value)!==1){
            throw new InvalidArgumentException('Bybit '.$field.' must be an explicit decimal string.');
        }
        if(!$allowZero&&preg_match('/^0+(?:\.0+)?$/',$value)===1){
            throw new InvalidArgumentException('Bybit '.$field.' must be positive.');
        }
        return $value;
    }

    private function integerString(mixed $value,string $field):string
    {
        if(!is_string($value)&&!is_int($value))throw new InvalidArgumentException('Bybit '.$field.' is missing.');
        $text=(string)$value;
        if(preg_match('/^[0-9]+$/',$text)!==1)throw new InvalidArgumentException('Bybit '.$field.' must be an unsigned integer.');
        return $text;
    }
}
