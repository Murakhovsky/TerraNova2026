<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Http;

use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use InvalidArgumentException;
use Kernel\Resilience\ExternalCallExecutor;
use Kernel\Resilience\ExternalCallPolicy;
use RuntimeException;
use Throwable;

final readonly class SafeMarketJsonHttpClient implements MarketJsonHttpClientInterface
{
    private const MAX_BODY_BYTES=4_194_304;

    public function __construct(private ExternalCallExecutor $calls){}

    public function get(
        string $organizationId,
        string $serviceKey,
        string $url,
        array $headers,
        array $allowedHosts,
    ):string{
        [$host,$ip]=$this->resolveEndpoint($url,$allowedHosts);
        $safeHeaders=['Accept: application/json','User-Agent: COS-Capital-Markets/0.3'];
        foreach($headers as $header){
            if(!is_string($header)||trim($header)===''||str_contains($header,"")||str_contains($header,"
")){
                throw new InvalidArgumentException('Market HTTP header is invalid.');
            }
            $safeHeaders[]=trim($header);
        }

        return $this->calls->execute(
            $organizationId,
            $serviceKey,
            fn():string=>$this->fetch($url,$host,$ip,$safeHeaders),
            new ExternalCallPolicy(
                maxAttempts:3,
                baseDelayMilliseconds:200,
                maxDelayMilliseconds:1600,
                failureThreshold:4,
                circuitOpenSeconds:60,
            ),
        );
    }

    /** @param list<string> $allowedHosts @return array{string,string} */
    private function resolveEndpoint(string $url,array $allowedHosts):array
    {
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'){
            throw new InvalidArgumentException('Market-data endpoint must use HTTPS.');
        }
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])){
            throw new InvalidArgumentException('Market-data endpoint must not contain credentials or fragments.');
        }
        if(isset($parts['port'])&&(int)$parts['port']!==443){
            throw new InvalidArgumentException('Market-data endpoint may use only port 443.');
        }

        $host=strtolower(trim((string)($parts['host']??'')));
        $allowed=array_values(array_unique(array_map(static fn(string $item):string=>strtolower(trim($item)),$allowedHosts)));
        if($host===''||!in_array($host,$allowed,true)){
            throw new InvalidArgumentException('Market-data endpoint host is not allowlisted.');
        }
        if(!preg_match('/^[a-z0-9.-]+$/',$host)){
            throw new InvalidArgumentException('Market-data endpoint host is invalid.');
        }

        $ips=filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false?[$host]:(gethostbynamel($host)?:[]);
        $public=[];
        foreach($ips as $ip){
            if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false){
                $public[]=$ip;
            }
        }
        $public=array_values(array_unique($public));
        sort($public,SORT_STRING);
        if($public===[])throw new InvalidArgumentException('Market-data endpoint did not resolve to a public IPv4 address.');

        return [$host,$public[0]];
    }

    /** @param list<string> $headers */
    private function fetch(string $url,string $host,string $ip,array $headers):string
    {
        if(!function_exists('curl_init'))throw new RuntimeException('cURL extension is required for Market Intelligence.');
        $curl=curl_init($url);
        if($curl===false)throw new RuntimeException('Market-data cURL initialization failed.');

        $body='';$tooLarge=false;
        $options=[
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RESOLVE=>[$host.':443:'.$ip],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge):int{
                if(strlen($body)+strlen($chunk)>self::MAX_BODY_BYTES){$tooLarge=true;return 0;}
                $body.=$chunk;
                return strlen($chunk);
            },
        ];
        if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))$options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
        curl_setopt_array($curl,$options);

        $ok=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        $contentType=(string)(curl_getinfo($curl,CURLINFO_CONTENT_TYPE)?:'');
        $error=curl_error($curl);
        curl_close($curl);

        if($tooLarge)throw new InvalidArgumentException('Market-data response exceeds 4 MB.');
        if($ok===false||$status===0){
            throw new RuntimeException('Market-data request failed'.($error!==''?': '.mb_substr($error,0,240):'.'));
        }
        if($status>=200&&$status<300){
            if($contentType!==''&&!str_contains(strtolower($contentType),'json')){
                throw new InvalidArgumentException('Market-data provider returned a non-JSON content type.');
            }
            if(trim($body)==='')throw new InvalidArgumentException('Market-data provider returned an empty body.');
            try{json_decode($body,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
            catch(Throwable){throw new InvalidArgumentException('Market-data provider returned malformed JSON.');}
            return $body;
        }
        if(in_array($status,[408,425,429],true)||$status>=500){
            throw new RuntimeException('Market-data provider returned retryable HTTP '.$status.'.');
        }
        throw new InvalidArgumentException('Market-data provider rejected request with HTTP '.$status.'.');
    }
}
