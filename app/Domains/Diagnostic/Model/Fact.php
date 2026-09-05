<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use DateTimeImmutable;
use InvalidArgumentException;
final readonly class Fact
{
    /** @param list<string> $evidenceIds @param list<FactRevision> $revisions */
    public function __construct(public string $id, public string $diagnosticId, public string $key, public mixed $value, public string $valueType, public FactStatus $status, public float $confidence, public string $source, public array $evidenceIds, public DateTimeImmutable $createdAt, public DateTimeImmutable $updatedAt, public array $revisions=[])
    {
        if ($id==='' || $diagnosticId==='' || $key==='' || $valueType==='' || $source==='' || $confidence<0 || $confidence>1 || $updatedAt<$createdAt) throw new InvalidArgumentException('Invalid fact.');
        if ($status===FactStatus::Known && $value===null) throw new InvalidArgumentException('A known fact requires a value.');
    }
    /** @param list<string> $evidenceIds */
    public function revise(mixed $value, FactStatus $status, float $confidence, string $reason, string $source, array $evidenceIds, DateTimeImmutable $at): self
    {
        $revision=new FactRevision($this->id,count($this->revisions)+1,$this->value,$value,$reason,$source,$evidenceIds,$at);
        return new self($this->id,$this->diagnosticId,$this->key,$value,$this->valueType,$status,$confidence,$source,$evidenceIds,$this->createdAt,$at,[...$this->revisions,$revision]);
    }
}
