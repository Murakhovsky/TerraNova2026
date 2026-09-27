<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;

final readonly class IcpCriteria
{
    /** @param list<string> $industries @param list<string> $regions @param list<string> $technologies @param list<string> $requiredSignalTypes */
    public function __construct(
        public array $industries = [],
        public array $regions = [],
        public ?int $minEmployees = null,
        public ?int $maxEmployees = null,
        public array $technologies = [],
        public array $requiredSignalTypes = [],
    ) {
        foreach (['industries'=>$industries,'regions'=>$regions,'technologies'=>$technologies,'requiredSignalTypes'=>$requiredSignalTypes] as $field=>$values) {
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '') throw new InvalidArgumentException('ICP '.$field.' contains an invalid value.');
            }
        }
        if ($minEmployees !== null && $minEmployees < 0) throw new InvalidArgumentException('ICP minEmployees must be non-negative.');
        if ($maxEmployees !== null && $maxEmployees < 0) throw new InvalidArgumentException('ICP maxEmployees must be non-negative.');
        if ($minEmployees !== null && $maxEmployees !== null && $minEmployees > $maxEmployees) {
            throw new InvalidArgumentException('ICP employee range is invalid.');
        }
        if ($industries === [] && $regions === [] && $minEmployees === null && $maxEmployees === null && $technologies === [] && $requiredSignalTypes === []) {
            throw new InvalidArgumentException('ICP criteria must configure at least one qualification group.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'industries'=>self::normalize($this->industries),
            'regions'=>self::normalize($this->regions),
            'min_employees'=>$this->minEmployees,
            'max_employees'=>$this->maxEmployees,
            'technologies'=>self::normalize($this->technologies),
            'required_signal_types'=>self::normalize($this->requiredSignalTypes),
        ];
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        return new self(
            self::strings($value['industries']??[]),
            self::strings($value['regions']??[]),
            isset($value['min_employees']) ? (int)$value['min_employees'] : null,
            isset($value['max_employees']) ? (int)$value['max_employees'] : null,
            self::strings($value['technologies']??[]),
            self::strings($value['required_signal_types']??[]),
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('ICP list value is invalid.');
        return self::normalize(array_map('strval',$value));
    }

    /** @param list<string> $values @return list<string> */
    private static function normalize(array $values): array
    {
        $out=[];
        foreach($values as $value){
            $value=strtolower(trim($value));
            if($value!=='')$out[$value]=true;
        }
        return array_keys($out);
    }
}
