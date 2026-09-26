<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;

final readonly class ScoreDimension
{
    /**
     * @param list<string> $evidenceIds
     */
    public function __construct(
        public int $score,
        public string $reason,
        public array $evidenceIds,
        public string $modelVersion,
    ) {
        if ($score < 0 || $score > 100) {
            throw new InvalidArgumentException('Growth score dimension must be between 0 and 100.');
        }

        if (trim($reason) === '' || trim($modelVersion) === '') {
            throw new InvalidArgumentException('Growth score dimension requires reason and model version.');
        }

        if ($evidenceIds === []) {
            throw new InvalidArgumentException('Growth score dimension requires evidence.');
        }

        foreach ($evidenceIds as $evidenceId) {
            if (trim($evidenceId) === '') {
                throw new InvalidArgumentException('Growth score evidence id must not be empty.');
            }
        }
    }

    /** @return array{score:int,reason:string,evidence_ids:list<string>,model_version:string} */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'reason' => $this->reason,
            'evidence_ids' => $this->evidenceIds,
            'model_version' => $this->modelVersion,
        ];
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        $score=$value['score']??null;
        if(!is_int($score))throw new InvalidArgumentException('Growth score dimension score must be an integer.');
        $evidence=$value['evidence_ids']??null;
        if(!is_array($evidence)||!array_is_list($evidence))throw new InvalidArgumentException('Growth score dimension evidence must be a list.');
        return new self(
            $score,
            (string)($value['reason']??''),
            array_values(array_map('strval',$evidence)),
            (string)($value['model_version']??''),
        );
    }
}
