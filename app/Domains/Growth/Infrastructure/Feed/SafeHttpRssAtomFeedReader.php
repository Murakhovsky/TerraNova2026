<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Feed;

use Domains\Growth\Application\Contract\GrowthFeedReaderInterface;
use Domains\Growth\Application\DTO\ExternalFeedEntry;
use InvalidArgumentException;
use Kernel\Resilience\ExternalCallExecutor;
use Kernel\Resilience\ExternalCallPolicy;
use RuntimeException;

final readonly class SafeHttpRssAtomFeedReader implements GrowthFeedReaderInterface
{
    private const MAX_BODY_BYTES=2_097_152;

    public function __construct(
        private ExternalCallExecutor $calls,
        private RssAtomFeedParser $parser,
    ) {}

    public function read(string $organizationId,string $url,int $limit):array
    {
        if($limit<1||$limit>200)throw new InvalidArgumentException('RSS/Atom reader limit must be between 1 and 200.');
        [$host,$ip]=$this->resolvePublicEndpoint($url);

        $body=$this->calls->execute(
            $organizationId,
            'growth.rss_atom.'.substr(hash('sha256',$host),0,16),
            fn():string=>$this->fetch($url,$host,$ip),
            new ExternalCallPolicy(maxAttempts:3,baseDelayMilliseconds:150,maxDelayMilliseconds:1200,failureThreshold:4,circuitOpenSeconds:60),
        );

        return $this->parser->parse($body,$limit);
    }

    /** @return array{string,string} */
    private function resolvePublicEndpoint(string $url):array
    {
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'){
            throw new InvalidArgumentException('RSS/Atom endpoint must use HTTPS.');
        }
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])){
            throw new InvalidArgumentException('RSS/Atom endpoint must not contain credentials or fragment.');
        }
        if(isset($parts['port'])&&(int)$parts['port']!==443){
            throw new InvalidArgumentException('RSS/Atom endpoint may use only port 443.');
        }

        $host=strtolower(trim((string)($parts['host']??'')));
        if($host===''||$host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local')||str_ends_with($host,'.internal')){
            throw new InvalidArgumentException('RSS/Atom endpoint host is not allowed.');
        }
        if(!preg_match('/^[a-z0-9.-]+$/',$host)){
            throw new InvalidArgumentException('RSS/Atom endpoint host must use an ASCII DNS name or IPv4 address.');
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
        if($public===[])throw new InvalidArgumentException('RSS/Atom endpoint did not resolve to a public IPv4 address.');

        return [$host,$public[0]];
    }

    private function fetch(string $url,string $host,string $ip):string
    {
        if(!function_exists('curl_init'))throw new RuntimeException('cURL extension is required for RSS/Atom collection.');

        $curl=curl_init($url);
        if($curl===false)throw new RuntimeException('RSS/Atom cURL initialization failed.');

        $body='';
        $tooLarge=false;
        $options=[
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_HTTPHEADER=>[
                'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.5',
                'User-Agent: COS-Growth-RSS/0.26',
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
        $error=curl_error($curl);
        curl_close($curl);

        if($tooLarge)throw new InvalidArgumentException('RSS/Atom response exceeds 2 MB.');
        if($ok===false||$status===0){
            throw new RuntimeException('RSS/Atom request failed'.($error!==''?': '.mb_substr($error,0,300):'.'));
        }
        if($status>=200&&$status<300)return $body;
        if($status===408||$status===429||$status>=500){
            throw new RuntimeException('RSS/Atom provider returned retryable HTTP '.$status.'.');
        }
        throw new InvalidArgumentException('RSS/Atom provider rejected request with HTTP '.$status.'.');
    }
}
