<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Kraken;

use InvalidArgumentException;
use Throwable;

final class KrakenSpotPayloadParser
{
    /** @return array{bid:string,bid_size:string,ask:string,ask_size:string,volume24h:string} */
    public function ticker(string $json):array
    {
        $payload=$this->decode($json);
        $row=$this->singleResult($payload);
        foreach(['a','b','v'] as $k)if(!isset($row[$k])||!is_array($row[$k]))throw new InvalidArgumentException('Kraken ticker field missing: '.$k);
        return [
            'ask'=>$this->decimal($row['a'][0]??null,'ask'),
            'ask_size'=>$this->decimal($row['a'][2]??$row['a'][1]??null,'ask_size'),
            'bid'=>$this->decimal($row['b'][0]??null,'bid'),
            'bid_size'=>$this->decimal($row['b'][2]??$row['b'][1]??null,'bid_size'),
            'volume24h'=>$this->decimal($row['v'][1]??$row['v'][0]??null,'volume24h',true),
        ];
    }

    /** @return array{bids:list<array{price:string,quantity:string}>,asks:list<array{price:string,quantity:string}>,timestamp:int} */
    public function depth(string $json):array
    {
        $payload=$this->decode($json);$row=$this->singleResult($payload);
        $latest=0;$out=['bids'=>[],'asks'=>[]];
        foreach(['bids','asks'] as $side){
            $rows=$row[$side]??null;
            if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('Kraken depth side missing: '.$side);
            foreach($rows as $level){
                if(!is_array($level)||count($level)<2)throw new InvalidArgumentException('Kraken depth level invalid.');
                $out[$side][]=['price'=>$this->decimal($level[0]??null,'price'),'quantity'=>$this->decimal($level[1]??null,'quantity')];
                $ts=(int)floor((float)($level[2]??0)); if($ts>$latest)$latest=$ts;
            }
        }
        return ['bids'=>$out['bids'],'asks'=>$out['asks'],'timestamp'=>$latest];
    }

    private function decode(string $json):array
    {
        try{$p=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable){throw new InvalidArgumentException('Kraken response is not valid JSON.');}
        if(!is_array($p)||array_is_list($p))throw new InvalidArgumentException('Kraken response must be object.');
        $errors=$p['error']??[]; if(!is_array($errors)||$errors!==[])throw new InvalidArgumentException('Kraken response returned error.');
        return $p;
    }
    private function singleResult(array $payload):array
    {
        $result=$payload['result']??null;
        if(!is_array($result)||array_is_list($result)||count($result)!==1)throw new InvalidArgumentException('Kraken response must contain exactly one market result.');
        $row=reset($result); if(!is_array($row))throw new InvalidArgumentException('Kraken result row invalid.');
        return $row;
    }
    private function decimal(mixed $v,string $field,bool $zero=false):string
    {
        if((!is_string($v)&&!is_int($v))||preg_match('/^[0-9]+(?:\.[0-9]+)?$/',(string)$v)!==1)throw new InvalidArgumentException('Kraken '.$field.' must be decimal.');
        $s=(string)$v;if(!$zero&&preg_match('/^0+(?:\.0+)?$/',$s)===1)throw new InvalidArgumentException('Kraken '.$field.' must be positive.');return $s;
    }
}
