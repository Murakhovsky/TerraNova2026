<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Okx;

use InvalidArgumentException;
use Throwable;

final class OkxPublicPayloadParser
{
    /** @return array<string,mixed> */
    public function ticker(string $json,string $instId):array{return $this->row($json,$instId,'ticker');}
    /** @return array<string,mixed> */
    public function funding(string $json,string $instId):array{return $this->row($json,$instId,'funding');}
    /** @return array<string,mixed> */
    public function mark(string $json,string $instId):array{return $this->row($json,$instId,'mark price');}
    /** @return array<string,mixed> */
    public function openInterest(string $json,string $instId):array{return $this->row($json,$instId,'open interest');}
    /** @return array<string,mixed> */
    public function instrument(string $json,string $instId):array{return $this->row($json,$instId,'instrument');}

    /** @return array<string,mixed> */
    public function index(string $json,string $indexId):array
    {
        $payload=$this->payload($json,'index ticker');
        foreach($payload['data'] as $row){
            if(is_array($row)&&!array_is_list($row)&&strtoupper((string)($row['instId']??''))===strtoupper($indexId))return $row;
        }
        throw new InvalidArgumentException('OKX index ticker did not contain the requested instrument.');
    }

    /** @return array{bids:list<array{price:string,quantity_contracts:string,order_count:?int}>,asks:list<array{price:string,quantity_contracts:string,order_count:?int}>,timestamp:string,sequence:?string} */
    public function book(string $json):array
    {
        $payload=$this->payload($json,'order book');
        $row=$payload['data'][0]??null;
        if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException('OKX order book row is missing.');
        return [
            'bids'=>$this->levels($row['bids']??null),
            'asks'=>$this->levels($row['asks']??null),
            'timestamp'=>$this->unsigned($row['ts']??null,'book timestamp'),
            'sequence'=>isset($row['seqId'])?$this->unsigned($row['seqId'],'book sequence'):null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function fundingHistory(string $json,string $instId):array
    {
        $payload=$this->payload($json,'funding history');
        $out=[];$expected=strtoupper($instId);
        foreach($payload['data'] as $row){
            if(!is_array($row)||array_is_list($row)||strtoupper((string)($row['instId']??''))!==$expected)continue;
            $this->decimal($row['fundingRate']??null,'fundingRate');
            $this->unsigned($row['fundingTime']??null,'fundingTime');
            $out[]=$row;
        }
        return $out;
    }

    public function decimal(mixed $value,string $field,bool $positiveOnly=false):string
    {
        if(!is_string($value)||preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/',$value)!==1)throw new InvalidArgumentException('OKX '.$field.' must be decimal.');
        if($positiveOnly&&(str_starts_with($value,'-')||preg_match('/^0+(?:\.0+)?$/',$value)===1))throw new InvalidArgumentException('OKX '.$field.' must be positive.');
        return $value;
    }

    public function unsigned(mixed $value,string $field):string
    {
        if((!is_string($value)&&!is_int($value))||!ctype_digit((string)$value))throw new InvalidArgumentException('OKX '.$field.' must be unsigned integer.');
        return (string)$value;
    }

    public function precision(string $step):int
    {
        $canonical=rtrim($step,'0');$dot=strpos($canonical,'.');
        return $dot===false?0:strlen($canonical)-$dot-1;
    }

    /** @return array<string,mixed> */
    private function row(string $json,string $instId,string $label):array
    {
        $payload=$this->payload($json,$label);$expected=strtoupper($instId);
        foreach($payload['data'] as $row){
            if(is_array($row)&&!array_is_list($row)&&strtoupper((string)($row['instId']??''))===$expected)return $row;
        }
        throw new InvalidArgumentException('OKX '.$label.' did not contain the requested instrument.');
    }

    /** @return array{code:mixed,data:list<mixed>} */
    private function payload(string $json,string $label):array
    {
        try{$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(Throwable){throw new InvalidArgumentException('OKX '.$label.' response is not valid JSON.');}
        if(!is_array($payload)||array_is_list($payload)||(string)($payload['code']??'')!=='0'||!is_array($payload['data']??null)||!array_is_list($payload['data'])){
            throw new InvalidArgumentException('OKX '.$label.' response is invalid.');
        }
        return $payload;
    }

    /** @return list<array{price:string,quantity_contracts:string,order_count:?int}> */
    private function levels(mixed $rows):array
    {
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('OKX order-book levels are missing.');
        $out=[];
        foreach($rows as $row){
            if(!is_array($row)||count($row)<2)throw new InvalidArgumentException('OKX order-book level is invalid.');
            $price=$this->decimal($row[0]??null,'book price',true);
            $qty=$this->decimal($row[1]??null,'book quantity');
            if(str_starts_with($qty,'-'))throw new InvalidArgumentException('OKX book quantity cannot be negative.');
            $orders=null;
            if(isset($row[3])&&$row[3]!==''){$orders=(int)$this->unsigned($row[3],'book order count');}
            $out[]=['price'=>$price,'quantity_contracts'=>$qty,'order_count'=>$orders];
        }
        return $out;
    }
}
