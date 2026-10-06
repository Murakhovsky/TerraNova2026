<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Security;

use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;
use RuntimeException;

final readonly class MarketSourceCredentialResolver
{
    public function __construct(private CredentialVaultInterface $vault){}

    public function apiKey(string $organizationId,MarketSourceDescriptor $source,string $scope):string
    {
        if($source->credentialsReference===null){
            throw new InvalidArgumentException('Market-data source requires a credential reference.');
        }
        $credential=new Credential(
            'cm-market-'.substr(hash('sha256',$source->id->value()),0,32),
            OrganizationId::fromString($organizationId),
            $source->id->value(),
            'api_key',
            $source->credentialsReference,
            [$scope],
        );
        $material=$this->vault->resolve($credential);
        $value=$material['api_key']??$material['token']??'';
        if($value===''||str_contains($value,"")||str_contains($value,"
")){
            throw new RuntimeException('Market-data credential material does not contain a valid API key.');
        }
        return $value;
    }
}
