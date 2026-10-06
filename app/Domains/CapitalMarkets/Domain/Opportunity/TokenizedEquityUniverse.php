<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Opportunity;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class TokenizedEquityUniverse extends ValueObject
{
    /**
     * @param list<string> $underlyingInstrumentIds
     * @param list<string> $tokenizedInstrumentIds
     * @param list<string> $venues
     * @param list<string> $hypotheses
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public array $underlyingInstrumentIds,
        public array $tokenizedInstrumentIds,
        public array $venues,
        public array $hypotheses,
        public bool $enabled,
    ){
        if($this->id===''||trim($this->id)!==$this->id)throw new InvalidArgumentException('Universe id is required.');
        if($this->name===''||trim($this->name)!==$this->name)throw new InvalidArgumentException('Universe name is required.');
        if(!in_array($this->status,['ACTIVE','PAUSED','DISABLED'],true))throw new InvalidArgumentException('Universe status is invalid.');
        foreach([$this->underlyingInstrumentIds,$this->tokenizedInstrumentIds,$this->venues,$this->hypotheses] as $values){
            if(count($values)!==count(array_unique($values)))throw new InvalidArgumentException('Universe lists cannot contain duplicates.');
            foreach($values as $value)if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Universe list values must be non-empty strings.');
        }
        foreach($this->hypotheses as $hypothesis)if(!in_array($hypothesis,['H1','H2'],true))throw new InvalidArgumentException('Universe hypothesis must be H1 or H2.');
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload):self
    {
        $list=static function(array $source,string $key):array{
            $value=$source[$key]??[];
            if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException($key.' must be a list.');
            return array_values(array_map(static fn(mixed $v):string=>trim((string)$v),$value));
        };
        return new self(
            trim((string)($payload['id']??'default')),
            trim((string)($payload['name']??'Default Tokenized Equity Universe')),
            strtoupper(trim((string)($payload['status']??'ACTIVE'))),
            $list($payload,'underlying_instrument_ids'),
            $list($payload,'tokenized_instrument_ids'),
            $list($payload,'venues'),
            array_map('strtoupper',$list($payload,'hypotheses')),
            (bool)($payload['enabled']??true),
        );
    }

    public function allowsHypothesis(string $hypothesis):bool
    {
        return $this->hypotheses===[]||in_array($hypothesis,$this->hypotheses,true);
    }

    public function allowsUnderlying(string $instrumentId):bool
    {
        return $this->underlyingInstrumentIds===[]||in_array($instrumentId,$this->underlyingInstrumentIds,true);
    }

    public function allowsToken(string $instrumentId):bool
    {
        return $this->tokenizedInstrumentIds===[]||in_array($instrumentId,$this->tokenizedInstrumentIds,true);
    }

    public function allowsVenue(string $venueId):bool
    {
        return $this->venues===[]||in_array($venueId,$this->venues,true);
    }
}
