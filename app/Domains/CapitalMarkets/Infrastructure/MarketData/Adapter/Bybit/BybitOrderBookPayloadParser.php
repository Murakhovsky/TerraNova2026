<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit;

use InvalidArgumentException;
use Throwable;

final class BybitOrderBookPayloadParser
{
    /** @return array{symbol:string,bids:list<array{price:string,quantity:string}>,asks:list<array{price:string,quantity:string}>,time:string,sequence:string} */
    public function parse(string $json,string $expectedSymbol):array
    {
        try{$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(Throwable){throw new InvalidArgumentException('Bybit orderbook response is not valid JSON.');}
        if(!is_array($payload)||array_is_list($payload)||(string)($payload['retCode']??'')!=='0'){
            throw new InvalidArgumentException('Bybit orderbook response is invalid.');
        }
        $result=$payload['result']??null;
        if(!is_array($result)||array_is_list($result)||strtoupper((string)($result['s']??''))!==strtoupper($expectedSymbol)){
            throw new InvalidArgumentException('Bybit orderbook symbol mismatch.');
        }
        return [
            'symbol'=>strtoupper($expectedSymbol),
            'bids'=>$this->levels($result['b']??null,'bids'),
            'asks'=>$this->levels($result['a']??null,'asks'),
            'time'=>$this->integer($result['cts']??$result['ts']??$payload['time']??null,'timestamp'),
            'sequence'=>$this->integer($result['seq']??$result['u']??null,'sequence'),
        ];
    }

    /** @return list<array{price:string,quantity:string}> */
    private function levels(mixed $rows,string $side):array
    {
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('Bybit '.$side.' missing.');
        $out=[];
        foreach($rows as $row){
            if(!is_array($row)||count($row)<2)throw new InvalidArgumentException('Bybit orderbook level invalid.');
            $out[]=['price'=>$this->decimal($row[0]??null,'price'),'quantity'=>$this->decimal($row[1]??null,'quantity')];
        }
        return $out;
    }
    private function decimal(mixed $v,string $f):string
    {
        if(!is_string($v)||preg_match('/^[0-9]+(?:\.[0-9]+)?$/',$v)!==1||preg_match('/^0+(?:\.0+)?$/',$v)===1)throw new InvalidArgumentException('Bybit '.$f.' invalid.');
        return $v;
    }
    private function integer(mixed $v,string $f):string
    {
        if((!is_string($v)&&!is_int($v))||preg_match('/^[0-9]+$/',(string)$v)!==1)throw new InvalidArgumentException('Bybit '.$f.' invalid.');
        return (string)$v;
    }
}
