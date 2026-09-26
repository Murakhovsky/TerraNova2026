<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GrowthMarketDiscoveredAccount
{
    /**
     * @param array<string,scalar|null> $firmographics
     * @param list<string> $technologies
     * @param list<string> $hiring
     * @param list<string> $recentChanges
     * @param list<string> $signalTypes
     * @param list<string> $sourceReferences
     */
    public function __construct(
        public string $externalKey,
        public string $name,
        public string $canonicalDomain,
        public array $firmographics,
        public array $technologies,
        public array $hiring,
        public array $recentChanges,
        public array $signalTypes,
        public array $sourceReferences,
        public DateTimeImmutable $observedAt,
    ) {
        if(trim($externalKey)===''||mb_strlen($externalKey)>1000)throw new InvalidArgumentException('Market account external key is invalid.');
        if(trim($name)===''||mb_strlen($name)>220)throw new InvalidArgumentException('Market account name is invalid.');
        $domain=strtolower(trim($canonicalDomain));
        if($domain===''||mb_strlen($domain)>191||str_contains($domain,'://')||str_contains($domain,'/')){
            throw new InvalidArgumentException('Market account canonical domain is invalid.');
        }
        foreach($firmographics as $key=>$value){
            if(!is_string($key)||trim($key)===''||(!is_scalar($value)&&$value!==null)){
                throw new InvalidArgumentException('Market account firmographics must contain scalar facts.');
            }
        }
        foreach([
            'technologies'=>$technologies,'hiring'=>$hiring,'recentChanges'=>$recentChanges,
            'signalTypes'=>$signalTypes,'sourceReferences'=>$sourceReferences,
        ] as $field=>$values){
            if(!array_is_list($values))throw new InvalidArgumentException('Market account '.$field.' must be a list.');
            foreach($values as $value){
                if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Market account '.$field.' contains an invalid value.');
            }
        }
        if($sourceReferences===[])throw new InvalidArgumentException('Market account requires at least one source reference.');
    }
}
