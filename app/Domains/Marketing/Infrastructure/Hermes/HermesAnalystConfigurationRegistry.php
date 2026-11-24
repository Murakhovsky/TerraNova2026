<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Hermes;

use DomainException;
use JsonException;

/** Operator-only configuration with no runtime setters. */
final readonly class HermesAnalystConfigurationRegistry
{
    public function __construct(private string $profilesJson='{}'){}

    public function forTenant(string $org):?HermesAnalystRuntimeConfiguration
    {
        if(strlen($this->profilesJson)>65536)throw new DomainException('Hermes profile registry exceeds bounds.');
        try{$decoded=json_decode($this->profilesJson,false,16,JSON_THROW_ON_ERROR);}
        catch(JsonException){throw new DomainException('Hermes profile registry is malformed.');}
        if(!$decoded instanceof \stdClass||count(get_object_vars($decoded))>100){
            throw new DomainException('Hermes profile registry must be a bounded tenant map.');
        }
        $profiles=get_object_vars($decoded);
        $configuration=null;$urls=[];$references=[];
        foreach($profiles as $tenant=>$raw){
            if(!$raw instanceof \stdClass)throw new DomainException('Hermes tenant profile is malformed.');
            $profile=new HermesAnalystRuntimeConfiguration((string)$tenant,get_object_vars($raw));
            // URL host is case-insensitive; the named profile path is kept exact.
            $parts=parse_url($profile->connection->profileBaseUrl);
            $url=strtolower((string)$parts['host']).$parts['path'];
            $reference=$profile->connection->credential->secretReference;
            if(isset($urls[$url])||isset($references[$reference])){
                throw new DomainException('Hermes tenant profiles must not share URL or credential reference.');
            }
            $urls[$url]=true;$references[$reference]=true;
            if((string)$tenant===$org)$configuration=$profile;
        }
        return $configuration;
    }
}
