<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use JsonException;

/**
 * Preserve decimal lexemes in JSON: json_decode normally turns prices into
 * PHP binary floats and silently loses precision before canonical Decimal.
 *
 * Only valid JSON is transformed; quoted strings (including escapes) are
 * copied verbatim. Every numeric JSON token becomes a decimal string.
 */
final class StreamingExactJsonNumbers
{
    /** @return array<string,mixed> */
    public static function object(string $json):array
    {
        if(strlen($json)>2097152)throw new DomainException('Streaming JSON exceeds input limit.');
        try{
            $test=json_decode($json,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        }catch(JsonException){
            throw new DomainException('Streaming provider message is not valid JSON.');
        }
        if(!is_array($test)||array_is_list($test))throw new DomainException('Streaming JSON must be an object.');

        $length=strlen($json);$output='';
        for($i=0;$i<$length;){
            $char=$json[$i];
            if($char==='"'){
                $start=$i++;
                $escaped=false;
                while($i<$length){
                    $next=$json[$i++];
                    if($escaped){$escaped=false;continue;}
                    if($next==='\\'){$escaped=true;continue;}
                    if($next==='"')break;
                }
                $output.=substr($json,$start,$i-$start);
                continue;
            }
            if($char==='-'||($char>='0'&&$char<='9')){
                $start=$i++;
                while($i<$length&&str_contains('0123456789.eE+-',$json[$i]))$i++;
                $token=substr($json,$start,$i-$start);
                $output.='"'.$token.'"';
                continue;
            }
            $output.=$char;$i++;
        }
        try{$result=json_decode($output,true,32,JSON_THROW_ON_ERROR);}
        catch(JsonException){throw new DomainException('Failed to preserve provider numeric evidence.');}
        if(!is_array($result)||array_is_list($result))throw new DomainException('Streaming JSON must remain an object.');
        return $result;
    }
}
