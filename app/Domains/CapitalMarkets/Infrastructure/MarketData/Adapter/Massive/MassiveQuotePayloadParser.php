<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Massive;

use InvalidArgumentException;
use Throwable;

final class MassiveQuotePayloadParser
{
    /** @return array{symbol:string,bidPrice:string,bidSize:string,askPrice:string,askSize:string,sequence:string,sipTimestampNs:string} */
    public function parse(string $json,string $expectedSymbol):array
    {
        try{$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(Throwable){throw new InvalidArgumentException('Massive quote response is not valid JSON.');}
        if(!is_array($payload)||array_is_list($payload))throw new InvalidArgumentException('Massive quote response must be an object.');
        if(strtoupper((string)($payload['status']??''))!=='OK')throw new InvalidArgumentException('Massive quote response status is not OK.');

        $decodedResults=$payload['results']??null;
        if(!is_array($decodedResults)||array_is_list($decodedResults)){
            throw new InvalidArgumentException('Massive quote response results object is missing.');
        }
        $symbol=$decodedResults['T']??null;
        if(!is_string($symbol)||trim($symbol)===''){
            throw new InvalidArgumentException('Massive T field is missing.');
        }
        $symbol=trim($symbol);

        $results=$this->resultsObject($json);
        if(strtoupper($symbol)!==strtoupper($expectedSymbol)){
            throw new InvalidArgumentException('Massive quote response symbol does not match the requested symbol.');
        }

        return [
            'symbol'=>$symbol,
            'bidPrice'=>$this->numberField($results,'p'),
            'bidSize'=>$this->integerField($results,'s'),
            'askPrice'=>$this->numberField($results,'P'),
            'askSize'=>$this->integerField($results,'S'),
            'sequence'=>$this->integerField($results,'q'),
            'sipTimestampNs'=>$this->integerField($results,'t'),
        ];
    }

    private function resultsObject(string $json):string
    {
        if(preg_match('/"results"\s*:\s*\{/',$json,$match,PREG_OFFSET_CAPTURE)!==1){
            throw new InvalidArgumentException('Massive quote response results object is missing.');
        }
        $offset=$match[0][1]+strlen($match[0][0])-1;
        $depth=0;$inString=false;$escape=false;$length=strlen($json);
        for($i=$offset;$i<$length;$i++){
            $char=$json[$i];
            if($inString){
                if($escape){$escape=false;continue;}
                if($char==='\\'){$escape=true;continue;}
                if($char==='"')$inString=false;
                continue;
            }
            if($char==='"'){$inString=true;continue;}
            if($char==='{'){$depth++;continue;}
            if($char==='}'){
                $depth--;
                if($depth===0)return substr($json,$offset,$i-$offset+1);
            }
        }
        throw new InvalidArgumentException('Massive quote response results object is malformed.');
    }

    private function numberField(string $json,string $field):string
    {
        $pattern='/"'.preg_quote($field,'/').'"\s*:\s*(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)/';
        if(preg_match($pattern,$json,$matches)!==1)throw new InvalidArgumentException('Massive '.$field.' numeric field is missing.');
        return $matches[1];
    }

    private function integerField(string $json,string $field):string
    {
        $value=$this->numberField($json,$field);
        if(preg_match('/^[0-9]+$/',$value)!==1)throw new InvalidArgumentException('Massive '.$field.' must be an unsigned integer.');
        return $value;
    }
}
