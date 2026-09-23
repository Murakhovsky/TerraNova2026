<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;

final readonly class GrowthExperimentVariant
{
    /** @param array<string,mixed> $config */
    public function __construct(
        public string $key,
        public string $name,
        public int $allocationWeight,
        public array $config,
    ) {
        if(!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/',$key)){
            throw new InvalidArgumentException('Growth experiment variant key is invalid.');
        }
        if(trim($name)===''||mb_strlen($name)>191){
            throw new InvalidArgumentException('Growth experiment variant name is invalid.');
        }
        if($allocationWeight<1||$allocationWeight>10000){
            throw new InvalidArgumentException('Growth experiment variant allocation weight must be between 1 and 10000.');
        }
        if($config===[]||array_is_list($config)){
            throw new InvalidArgumentException('Growth experiment variant config must be a non-empty object.');
        }
        json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input):self
    {
        $key=$input['key']??null;
        $name=$input['name']??null;
        $weight=$input['allocation_weight']??null;
        $config=$input['config']??null;
        if(!is_string($key)||!is_string($name)||!is_int($weight)||!is_array($config)){
            throw new InvalidArgumentException('Growth experiment variant payload is invalid.');
        }
        return new self(strtolower(trim($key)),trim($name),$weight,$config);
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'key'=>$this->key,
            'name'=>$this->name,
            'allocation_weight'=>$this->allocationWeight,
            'config'=>$this->config,
        ];
    }
}
