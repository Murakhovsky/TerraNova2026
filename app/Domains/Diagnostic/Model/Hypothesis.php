<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use DomainException;
use InvalidArgumentException;
final readonly class Hypothesis
{
    /** @param list<string> $supportingEvidence @param list<string> $contradictingEvidence @param list<string> $requiredEvidence */
    public function __construct(public string $id, public string $statement, public HypothesisStatus $status, public float $confidence, public array $supportingEvidence=[], public array $contradictingEvidence=[], public array $requiredEvidence=[])
    { if ($id==='' || $statement==='' || $confidence<0 || $confidence>1) throw new InvalidArgumentException('Invalid hypothesis.'); }
    public function transition(HypothesisStatus $status, float $confidence): self
    {
        $allowed=match($this->status){HypothesisStatus::Open=>[HypothesisStatus::Supported,HypothesisStatus::Rejected,HypothesisStatus::Inconclusive],HypothesisStatus::Supported=>[HypothesisStatus::Confirmed,HypothesisStatus::Rejected,HypothesisStatus::Inconclusive],default=>[]};
        if (!in_array($status,$allowed,true)) throw new DomainException('Invalid hypothesis transition.');
        return new self($this->id,$this->statement,$status,$confidence,$this->supportingEvidence,$this->contradictingEvidence,$this->requiredEvidence);
    }
}
