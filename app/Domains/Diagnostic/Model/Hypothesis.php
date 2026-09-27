<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use DomainException;
use InvalidArgumentException;

final readonly class Hypothesis
{
    /** @param list<string> $supportingEvidence @param list<string> $contradictingEvidence @param list<string> $requiredEvidence */
    public function __construct(
        public string $id,
        public string $statement,
        public HypothesisStatus $status,
        public float $confidence,
        public array $supportingEvidence = [],
        public array $contradictingEvidence = [],
        public array $requiredEvidence = [],
    ) {
        if ($id === '' || $statement === '' || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Invalid hypothesis.');
        }
    }

    public function transition(HypothesisStatus $status, float $confidence): self
    {
        $allowed = match ($this->status) {
            HypothesisStatus::Unverified, HypothesisStatus::Open => [HypothesisStatus::Supported, HypothesisStatus::Rejected],
            HypothesisStatus::Supported => [HypothesisStatus::StronglySupported, HypothesisStatus::Confirmed, HypothesisStatus::Rejected, HypothesisStatus::Inconclusive],
            HypothesisStatus::StronglySupported => [HypothesisStatus::ConfirmedRootCause, HypothesisStatus::Rejected],
            default => [],
        };
        if (!in_array($status, $allowed, true)) {
            throw new DomainException('Invalid hypothesis transition.');
        }
        return new self($this->id, $this->statement, $status, $confidence, $this->supportingEvidence, $this->contradictingEvidence, $this->requiredEvidence);
    }

    public function isConfirmedRootCause(): bool
    {
        return in_array($this->status, [HypothesisStatus::ConfirmedRootCause, HypothesisStatus::Confirmed], true);
    }
}
