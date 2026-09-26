<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Market;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthMarketSourceInterface;
use Domains\Growth\Application\DTO\GrowthMarketDiscoveredAccount;
use Domains\Growth\Application\DTO\GrowthMarketDiscoveryBatch;
use Domains\Growth\Domain\GrowthMarketUniverse;
use InvalidArgumentException;
use Kernel\Resilience\ExternalCallExecutor;
use Kernel\Resilience\ExternalCallPolicy;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;
use RuntimeException;
use Throwable;

final readonly class CredentialedJsonGrowthMarketSource implements GrowthMarketSourceInterface
{
    private const MAX_BODY_BYTES=4_194_304;

    public function __construct(
        private ExternalCallExecutor $calls,
        private CredentialVaultInterface $vault,
    ) {}

    public function type():string{return GrowthMarketUniverse::SOURCE_CREDENTIALED_JSON;}

    public function discover(GrowthMarketUniverse $universe,?string $cursor,int $limit):GrowthMarketDiscoveryBatch
    {
        if($limit<1||$limit>200)throw new InvalidArgumentException('Growth market discovery limit must be between 1 and 200.');
        if($cursor!==null&&(trim($cursor)===''||mb_strlen($cursor)>1000))throw new InvalidArgumentException('Growth market cursor is invalid.');

        [$host,$ip]=$this->resolvePublicEndpoint($universe->url);
        $credential=new Credential(
            'growth-market-'.$universe->id,
            $universe->organizationId,
            $universe->id,
            $universe->authMode,
            $universe->credentialReference,
            ['growth.market.read'],
        );
        $material=$this->vault->resolve($credential);
        $authHeader=$this->authorizationHeader($universe,$material);
        $requestUrl=$this->withPaging($universe->url,$cursor,$limit);

        $body=$this->calls->execute(
            $universe->organizationId->value(),
            'growth.market.'.substr(hash('sha256',$host),0,16),
            fn():string=>$this->fetch($requestUrl,$host,$ip,$authHeader),
            new ExternalCallPolicy(maxAttempts:3,baseDelayMilliseconds:200,maxDelayMilliseconds:1600,failureThreshold:4,circuitOpenSeconds:60),
        );

        return $this->parse($body,$limit);
    }

    /** @param array<string,string> $material */
    private function authorizationHeader(GrowthMarketUniverse $universe,array $material):string
    {
        if($universe->authMode===GrowthMarketUniverse::AUTH_BEARER){
            $token=$material['token']??'';
            if($token==='')throw new RuntimeException('Market credential material does not contain bearer token.');
            return 'Authorization: Bearer '.$token;
        }
        if($universe->authMode===GrowthMarketUniverse::AUTH_API_KEY_HEADER){
            $apiKey=$material['api_key']??'';
            if($apiKey==='')throw new RuntimeException('Market credential material does not contain API key.');
            if($universe->apiKeyHeader===null)throw new InvalidArgumentException('Market API-key source is missing header name.');
            return $universe->apiKeyHeader.': '.$apiKey;
        }
        throw new InvalidArgumentException('Growth market auth mode is unsupported.');
    }

    private function withPaging(string $url,?string $cursor,int $limit):string
    {
        $query=['limit'=>$limit];
        if($cursor!==null)$query['cursor']=$cursor;
        return $url.(str_contains($url,'?')?'&':'?').http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }

    /** @return array{string,string} */
    private function resolvePublicEndpoint(string $url):array
    {
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'){
            throw new InvalidArgumentException('Growth market endpoint must use HTTPS.');
        }
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])){
            throw new InvalidArgumentException('Growth market endpoint must not contain credentials or fragment.');
        }
        if(isset($parts['port'])&&(int)$parts['port']!==443){
            throw new InvalidArgumentException('Growth market endpoint may use only port 443.');
        }
        $host=strtolower(trim((string)($parts['host']??'')));
        if($host===''||$host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local')||str_ends_with($host,'.internal')){
            throw new InvalidArgumentException('Growth market endpoint host is not allowed.');
        }
        if(!preg_match('/^[a-z0-9.-]+$/',$host)){
            throw new InvalidArgumentException('Growth market endpoint host must use an ASCII DNS name or IPv4 address.');
        }
        $ips=filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false?[$host]:(gethostbynamel($host)?:[]);
        $public=[];
        foreach($ips as $ip){
            if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false)$public[]=$ip;
        }
        $public=array_values(array_unique($public));sort($public,SORT_STRING);
        if($public===[])throw new InvalidArgumentException('Growth market endpoint did not resolve to a public IPv4 address.');
        return [$host,$public[0]];
    }

    private function fetch(string $url,string $host,string $ip,string $authHeader):string
    {
        if(!function_exists('curl_init'))throw new RuntimeException('cURL extension is required for Growth market discovery.');
        $curl=curl_init($url);
        if($curl===false)throw new RuntimeException('Growth market cURL initialization failed.');

        $body='';$tooLarge=false;
        $options=[
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: COS-Growth-Market/0.50',$authHeader],
            CURLOPT_RESOLVE=>[$host.':443:'.$ip],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge):int{
                if(strlen($body)+strlen($chunk)>self::MAX_BODY_BYTES){$tooLarge=true;return 0;}
                $body.=$chunk;return strlen($chunk);
            },
        ];
        if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))$options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
        curl_setopt_array($curl,$options);
        $ok=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        $contentType=(string)(curl_getinfo($curl,CURLINFO_CONTENT_TYPE)?:'');
        $error=curl_error($curl);curl_close($curl);

        if($tooLarge)throw new InvalidArgumentException('Growth market response exceeds 4 MB.');
        if($ok===false||$status===0)throw new RuntimeException('Growth market request failed'.($error!==''?': '.mb_substr($error,0,300):'.'));
        if($status>=200&&$status<300){
            if($contentType!==''&&!str_contains(strtolower($contentType),'json')){
                throw new InvalidArgumentException('Growth market provider returned non-JSON content type.');
            }
            return $body;
        }
        if($status===408||$status===429||$status>=500)throw new RuntimeException('Growth market provider returned retryable HTTP '.$status.'.');
        throw new InvalidArgumentException('Growth market provider rejected request with HTTP '.$status.'.');
    }

    private function parse(string $json,int $limit):GrowthMarketDiscoveryBatch
    {
        if(trim($json)==='')throw new InvalidArgumentException('Growth market response body is empty.');
        try{$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR);}
        catch(Throwable){throw new InvalidArgumentException('Growth market response is not valid JSON.');}
        if(!is_array($payload)||array_is_list($payload))throw new InvalidArgumentException('Growth market response must be an object.');
        $rows=$payload['items']??null;
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('Growth market response must contain an items list.');
        if(count($rows)>500)throw new InvalidArgumentException('Growth market response contains too many items.');
        if(count($rows)>$limit)throw new InvalidArgumentException('Growth market provider exceeded the requested item limit.');

        $items=[];$seen=[];$rejected=0;$errors=[];
        foreach($rows as $row){
            if(!is_array($row)||array_is_list($row)){
                $rejected++;
                if(count($errors)<5)$errors[]='Provider item must be an object.';
                continue;
            }
            try{
                $item=new GrowthMarketDiscoveredAccount(
                    $this->string($row['id']??null,1000,'id'),
                    $this->string($row['name']??null,220,'name'),
                    strtolower($this->string($row['canonical_domain']??null,191,'canonical_domain')),
                    $this->facts($row['firmographics']??[]),
                    $this->list($row['technologies']??[],'technologies'),
                    $this->list($row['hiring']??[],'hiring'),
                    $this->list($row['recent_changes']??[],'recent_changes'),
                    $this->list($row['signal_types']??[],'signal_types'),
                    $this->requiredList($row['source_references']??null,'source_references'),
                    new DateTimeImmutable($this->string($row['observed_at']??null,100,'observed_at')),
                );
            }catch(Throwable $error){
                $rejected++;
                if(count($errors)<5){
                    $message=trim($error->getMessage());
                    $errors[]=mb_substr($message!==''?$message:get_class($error),0,1000);
                }
                continue;
            }
            $key=hash('sha256',$item->externalKey);
            if(isset($seen[$key]))continue;
            $seen[$key]=true;$items[]=$item;
        }
        $next=$payload['next_cursor']??null;
        if($next!==null){
            if(!is_string($next)||trim($next)===''||mb_strlen($next)>1000)throw new InvalidArgumentException('Growth market next_cursor is invalid.');
            $next=trim($next);
        }
        return new GrowthMarketDiscoveryBatch($items,$next,$rejected,$errors);
    }

    /** @return array<string,scalar|null> */
    private function facts(mixed $value):array
    {
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('Market firmographics must be an object.');
        foreach($value as $key=>$item){
            if(!is_string($key)||trim($key)===''||(!is_scalar($item)&&$item!==null))throw new InvalidArgumentException('Market firmographics contains an invalid value.');
        }
        return $value;
    }

    /** @return list<string> */
    private function requiredList(mixed $value,string $field):array
    {
        $list=$this->list($value,$field);
        if($list===[])throw new InvalidArgumentException($field.' must not be empty.');
        return $list;
    }

    /** @return list<string> */
    private function list(mixed $value,string $field):array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException($field.' must be a list.');
        $out=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException($field.' contains an invalid value.');
            $out[trim($item)]=true;
        }
        return array_keys($out);
    }

    private function string(mixed $value,int $limit,string $field):string
    {
        if(!is_string($value)||trim($value)===''||mb_strlen(trim($value))>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return trim($value);
    }
}
