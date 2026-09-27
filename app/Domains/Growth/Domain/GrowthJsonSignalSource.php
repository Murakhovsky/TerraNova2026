<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class GrowthJsonSignalSource
{
    public const AUTH_BEARER='bearer';
    public const AUTH_API_KEY_HEADER='api_key_header';

    public function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly string $name,
        public readonly string $url,
        public readonly string $authMode,
        public readonly string $credentialReference,
        public readonly ?string $apiKeyHeader,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $signalType,
        public readonly float $confidence,
        private bool $enabled=true,
    ) {
        foreach([
            'id'=>$id,'name'=>$name,'url'=>$url,'authMode'=>$authMode,'credentialReference'=>$credentialReference,
            'subjectType'=>$subjectType,'subjectId'=>$subjectId,'signalType'=>$signalType,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth JsonSignalSource '.$field.' is required.');
        }
        if(mb_strlen($id)>80||mb_strlen($name)>160||mb_strlen($url)>1000||mb_strlen($credentialReference)>500||
            mb_strlen($subjectType)>80||mb_strlen($subjectId)>191||mb_strlen($signalType)>120){
            throw new InvalidArgumentException('Growth JsonSignalSource field exceeds its limit.');
        }
        if(!in_array($authMode,[self::AUTH_BEARER,self::AUTH_API_KEY_HEADER],true)){
            throw new InvalidArgumentException('Growth JsonSignalSource auth mode is unsupported.');
        }
        if($authMode===self::AUTH_BEARER&&$apiKeyHeader!==null){
            throw new InvalidArgumentException('Bearer Growth JsonSignalSource must not define apiKeyHeader.');
        }
        if($authMode===self::AUTH_API_KEY_HEADER){
            if($apiKeyHeader===null||!preg_match('/^X-[A-Za-z0-9-]{1,63}$/',$apiKeyHeader)){
                throw new InvalidArgumentException('API-key Growth JsonSignalSource requires a safe X-* header name.');
            }
        }

        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||trim((string)($parts['host']??''))===''){
            throw new InvalidArgumentException('Growth JsonSignalSource URL must be an absolute HTTPS URL.');
        }
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])){
            throw new InvalidArgumentException('Growth JsonSignalSource URL must not contain credentials or fragment.');
        }
        if(isset($parts['port'])&&(int)$parts['port']!==443){
            throw new InvalidArgumentException('Growth JsonSignalSource URL may use only port 443.');
        }
        if(isset($parts['query'])&&$parts['query']!==''){
            $query=[];
            parse_str((string)$parts['query'],$query);
            if(array_key_exists('limit',$query)){
                throw new InvalidArgumentException('Growth JsonSignalSource URL must not predefine reserved limit query parameter.');
            }
        }
        if($confidence<0.0||$confidence>1.0){
            throw new InvalidArgumentException('Growth JsonSignalSource confidence must be between 0 and 1.');
        }
    }

    public function enabled():bool{return $this->enabled;}
    public function enable():void{$this->enabled=true;}
    public function disable():void{$this->enabled=false;}

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'source_id'=>$this->id,'organization_id'=>$this->organizationId->value(),'name'=>$this->name,'url'=>$this->url,
            'auth_mode'=>$this->authMode,'credential_reference'=>$this->credentialReference,'api_key_header'=>$this->apiKeyHeader,
            'subject_type'=>$this->subjectType,'subject_id'=>$this->subjectId,'signal_type'=>$this->signalType,
            'confidence'=>$this->confidence,'enabled'=>$this->enabled,
        ];
    }
}
