<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Feed;

use Domains\Growth\Application\Contract\GrowthJsonSignalReaderInterface;
use Domains\Growth\Application\DTO\ExternalJsonSignalEntry;
use Domains\Growth\Domain\GrowthJsonSignalSource;
use InvalidArgumentException;
use Kernel\Resilience\ExternalCallExecutor;
use Kernel\Resilience\ExternalCallPolicy;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;
use RuntimeException;

final readonly class SafeHttpCredentialedJsonSignalReader implements GrowthJsonSignalReaderInterface
{
    private const MAX_BODY_BYTES=2_097_152;

    public function __construct(
        private ExternalCallExecutor $calls,
        private CredentialVaultInterface $vault,
        private CredentialedJsonSignalParser $parser,
    ) {}

    public function read(GrowthJsonSignalSource $source,int $limit):array
    {
        if($limit<1||$limit>200)throw new InvalidArgumentException('Credentialed JSON reader limit must be between 1 and 200.');
        [$host,$ip]=$this->resolvePublicEndpoint($source->url);
        $credential=new Credential(
            'growth-json-'.$source->id,
            $source->organizationId,
            $source->id,
            $source->authMode,
            $source->credentialReference,
            ['growth.signals.read'],
        );
        $material=$this->vault->resolve($credential);
        $authHeader=$this->authorizationHeader($source,$material);
        $requestUrl=$this->withLimit($source->url,$limit);

        $body=$this->calls->execute(
            $source->organizationId->value(),
            'growth.credentialed_json.'.substr(hash('sha256',$host),0,16),
            fn():string=>$this->fetch($requestUrl,$host,$ip,$authHeader),
            new ExternalCallPolicy(maxAttempts:3,baseDelayMilliseconds:150,maxDelayMilliseconds:1200,failureThreshold:4,circuitOpenSeconds:60),
        );

        return $this->parser->parse($body,$limit);
    }

    /** @param array<string,string> $material */
    private function authorizationHeader(GrowthJsonSignalSource $source,array $material):string
    {
        if($source->authMode===GrowthJsonSignalSource::AUTH_BEARER){
            $token=$material['token']??'';
            if($token==='')throw new RuntimeException('Credential material does not contain bearer token.');
            return 'Authorization: Bearer '.$token;
        }
        if($source->authMode===GrowthJsonSignalSource::AUTH_API_KEY_HEADER){
            $apiKey=$material['api_key']??'';
            if($apiKey==='')throw new RuntimeException('Credential material does not contain API key.');
            if($source->apiKeyHeader===null)throw new InvalidArgumentException('API-key source is missing header name.');
            return $source->apiKeyHeader.': '.$apiKey;
        }
        throw new InvalidArgumentException('Credentialed JSON auth mode is unsupported.');
    }

    private function withLimit(string $url,int $limit):string
    {
        $parts=parse_url($url);
        if(!is_array($parts))throw new InvalidArgumentException('Credentialed JSON endpoint URL is invalid.');
        $query=[];
        if(isset($parts['query'])&&$parts['query']!=='')parse_str((string)$parts['query'],$query);
        if(array_key_exists('limit',$query)){
            throw new InvalidArgumentException('Credentialed JSON endpoint URL must not predefine reserved limit query parameter.');
        }
        $separator=str_contains($url,'?')?'&':'?';
        return $url.$separator.'limit='.$limit;
    }

    /** @return array{string,string} */
    private function resolvePublicEndpoint(string $url):array
    {
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'){
            throw new InvalidArgumentException('Credentialed JSON endpoint must use HTTPS.');
        }
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])){
            throw new InvalidArgumentException('Credentialed JSON endpoint must not contain credentials or fragment.');
        }
        if(isset($parts['port'])&&(int)$parts['port']!==443){
            throw new InvalidArgumentException('Credentialed JSON endpoint may use only port 443.');
        }

        $host=strtolower(trim((string)($parts['host']??'')));
        if($host===''||$host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local')||str_ends_with($host,'.internal')){
            throw new InvalidArgumentException('Credentialed JSON endpoint host is not allowed.');
        }
        if(!preg_match('/^[a-z0-9.-]+$/',$host)){
            throw new InvalidArgumentException('Credentialed JSON endpoint host must use an ASCII DNS name or IPv4 address.');
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
        if($public===[])throw new InvalidArgumentException('Credentialed JSON endpoint did not resolve to a public IPv4 address.');

        return [$host,$public[0]];
    }

    private function fetch(string $url,string $host,string $ip,string $authHeader):string
    {
        if(!function_exists('curl_init'))throw new RuntimeException('cURL extension is required for credentialed JSON collection.');

        $curl=curl_init($url);
        if($curl===false)throw new RuntimeException('Credentialed JSON cURL initialization failed.');

        $body='';
        $tooLarge=false;
        $options=[
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_HTTPHEADER=>[
                'Accept: application/json',
                'User-Agent: COS-Growth-JSON/0.28',
                $authHeader,
            ],
            CURLOPT_RESOLVE=>[$host.':443:'.$ip],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge):int{
                if(strlen($body)+strlen($chunk)>self::MAX_BODY_BYTES){
                    $tooLarge=true;
                    return 0;
                }
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

        if($tooLarge)throw new InvalidArgumentException('Credentialed JSON response exceeds 2 MB.');
        if($ok===false||$status===0){
            throw new RuntimeException('Credentialed JSON request failed'.($error!==''?': '.mb_substr($error,0,300):'.'));
        }
        if($status>=200&&$status<300){
            if($contentType!==''&&!str_contains(strtolower($contentType),'json')){
                throw new InvalidArgumentException('Credentialed JSON provider returned non-JSON content type.');
            }
            return $body;
        }
        if($status===408||$status===429||$status>=500){
            throw new RuntimeException('Credentialed JSON provider returned retryable HTTP '.$status.'.');
        }
        throw new InvalidArgumentException('Credentialed JSON provider rejected request with HTTP '.$status.'.');
    }
}
