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
        $raw=$profiles[$org]??null;
        if($raw===null)return null;
        if(!$raw instanceof \stdClass)throw new DomainException('Hermes tenant profile is malformed.');
        return new HermesAnalystRuntimeConfiguration($org,get_object_vars($raw));
    }
}
