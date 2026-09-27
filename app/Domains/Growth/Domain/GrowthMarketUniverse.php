<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class GrowthMarketUniverse
{
    public const SOURCE_CREDENTIALED_JSON='credentialed_json';
    public const AUTH_BEARER='bearer';
    public const AUTH_API_KEY_HEADER='api_key_header';

    public function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly string $name,
        public readonly string $sourceType,
        public readonly string $url,
        public readonly string $authMode,
        public readonly string $credentialReference,
        public readonly ?string $apiKeyHeader,
        public readonly string $profileId,
        public readonly int $profileRevision,
        public readonly int $minIcpFit,
        public readonly OpportunityType $opportunityType,
        public readonly GrowthMode $growthMode,
        public readonly string $targetDomain,
        private bool $enabled=true,
    ) {
        foreach([
            'id'=>$id,'name'=>$name,'sourceType'=>$sourceType,'url'=>$url,'authMode'=>$authMode,
            'credentialReference'=>$credentialReference,'profileId'=>$profileId,'targetDomain'=>$targetDomain,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth Market Universe '.$field.' is required.');
        }
        if(mb_strlen($id)>80||mb_strlen($name)>160||mb_strlen($sourceType)>80||mb_strlen($url)>1000||
            mb_strlen($credentialReference)>500||mb_strlen($profileId)>80||mb_strlen($targetDomain)>80){
            throw new InvalidArgumentException('Growth Market Universe field exceeds its limit.');
        }
        if($profileRevision<1)throw new InvalidArgumentException('Growth Market Universe ICP revision must be positive.');
        if($minIcpFit<0||$minIcpFit>100)throw new InvalidArgumentException('Growth Market Universe minimum ICP fit must be between 0 and 100.');
        if(!preg_match('/^[a-z][a-z0-9_-]*$/',$targetDomain)){
            throw new InvalidArgumentException('Growth Market Universe target Domain is invalid.');
        }
        if(!in_array($authMode,[self::AUTH_BEARER,self::AUTH_API_KEY_HEADER],true)){
            throw new InvalidArgumentException('Growth Market Universe auth mode is unsupported.');
        }
        if($authMode===self::AUTH_BEARER&&$apiKeyHeader!==null){
            throw new InvalidArgumentException('Bearer Growth Market Universe must not define apiKeyHeader.');
        }
        if($authMode===self::AUTH_API_KEY_HEADER){
            if($apiKeyHeader===null||!preg_match('/^X-[A-Za-z0-9-]{1,63}$/',$apiKeyHeader)){
                throw new InvalidArgumentException('API-key Growth Market Universe requires a safe X-* header name.');
            }
        }

        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||trim((string)($parts['host']??''))===''){
            throw new InvalidArgumentException('Growth Market Universe URL must be an absolute HTTPS URL.');
        }
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])){
            throw new InvalidArgumentException('Growth Market Universe URL must not contain credentials or fragment.');
        }
        if(isset($parts['port'])&&(int)$parts['port']!==443){
            throw new InvalidArgumentException('Growth Market Universe URL may use only port 443.');
        }
        if(isset($parts['query'])&&$parts['query']!==''){
            $query=[];
            parse_str((string)$parts['query'],$query);
            foreach(['limit','cursor'] as $reserved){
                if(array_key_exists($reserved,$query)){
                    throw new InvalidArgumentException('Growth Market Universe URL must not predefine reserved '.$reserved.' query parameter.');
                }
            }
        }
    }

    public function enabled():bool{return $this->enabled;}
    public function enable():void{$this->enabled=true;}
    public function disable():void{$this->enabled=false;}

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'universe_id'=>$this->id,'organization_id'=>$this->organizationId->value(),'name'=>$this->name,
            'source_type'=>$this->sourceType,'url'=>$this->url,'auth_mode'=>$this->authMode,
            'credential_reference'=>$this->credentialReference,'api_key_header'=>$this->apiKeyHeader,
            'profile_id'=>$this->profileId,'profile_revision'=>$this->profileRevision,'min_icp_fit'=>$this->minIcpFit,
            'opportunity_type'=>$this->opportunityType->value,'growth_mode'=>$this->growthMode->value,
            'target_domain'=>$this->targetDomain,'enabled'=>$this->enabled,
        ];
    }
}
