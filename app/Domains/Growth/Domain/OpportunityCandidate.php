<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DomainException;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class OpportunityCandidate
{
    private OpportunityCandidateStatus $status;

    /** @var list<string> */
    private array $signalIds;

    private ?OpportunityRationale $rationale = null;
    private ?OpportunityScore $score = null;
    private ?string $qualificationReason = null;
    private ?string $expectedValue = null;
    private ?string $recommendedPlay = null;
    private ?string $recommendedAction = null;

    /**
     * @param list<string> $signalIds
     */
    private function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly OpportunityType $type,
        public readonly GrowthMode $mode,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $targetDomain,
        array $signalIds,
    ) {
        foreach ([
            'id' => $id,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'targetDomain' => $targetDomain,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Growth candidate %s is required.', $field));
            }
        }

        $this->signalIds = self::references($signalIds, 'signal');
        $this->status = OpportunityCandidateStatus::Detected;
    }

    /**
     * @param list<string> $signalIds
     */
    public static function detect(
        string $id,
        OrganizationId $organizationId,
        OpportunityType $type,
        GrowthMode $mode,
        string $subjectType,
        string $subjectId,
        string $targetDomain,
        array $signalIds,
    ): self {
        return new self(
            $id,
            $organizationId,
            $type,
            $mode,
            $subjectType,
            $subjectId,
            $targetDomain,
            $signalIds,
        );
    }

    public function status(): OpportunityCandidateStatus
    {
        return $this->status;
    }

    /** @return list<string> */
    public function signalIds(): array
    {
        return $this->signalIds;
    }

    public function rationale(): ?OpportunityRationale
    {
        return $this->rationale;
    }

    public function score(): ?OpportunityScore
    {
        return $this->score;
    }

    public function qualificationReason(): ?string
    {
        return $this->qualificationReason;
    }

    public function expectedValue(): ?string
    {
        return $this->expectedValue;
    }

    public function recommendedPlay(): ?string
    {
        return $this->recommendedPlay;
    }

    public function recommendedAction(): ?string
    {
        return $this->recommendedAction;
    }

    public function startEnrichment(): void
    {
        $this->assertStatus([
            OpportunityCandidateStatus::Detected,
            OpportunityCandidateStatus::Monitoring,
        ], 'start enrichment');

        $this->status = OpportunityCandidateStatus::Enriching;
    }

    public function markResearched(OpportunityRationale $rationale): void
    {
        $this->assertStatus([
            OpportunityCandidateStatus::Detected,
            OpportunityCandidateStatus::Enriching,
        ], 'be marked researched');

        $this->rationale = $rationale;
        $this->status = OpportunityCandidateStatus::Researched;
    }

    public function applyScore(OpportunityScore $score): void
    {
        $this->assertStatus([OpportunityCandidateStatus::Researched], 'be scored');

        $this->score = $score;
        $this->status = OpportunityCandidateStatus::Scored;
    }

    public function qualify(string $reason): void
    {
        $this->assertStatus([OpportunityCandidateStatus::Scored], 'be qualified');

        $this->qualificationReason = self::required($reason, 'qualification reason');
        $this->status = OpportunityCandidateStatus::Qualified;
    }

    public function disqualify(string $reason): void
    {
        $this->assertNotTerminal('be disqualified');

        if ($this->status === OpportunityCandidateStatus::HandedOff) {
            throw new DomainException('Handed-off Growth candidate cannot be disqualified by Growth.');
        }

        $this->qualificationReason = self::required($reason, 'disqualification reason');
        $this->status = OpportunityCandidateStatus::Disqualified;
    }

    public function monitor(string $reason): void
    {
        $this->assertStatus([
            OpportunityCandidateStatus::Detected,
            OpportunityCandidateStatus::Researched,
            OpportunityCandidateStatus::Scored,
        ], 'move to monitoring');

        $this->qualificationReason = self::required($reason, 'monitoring reason');
        $this->status = OpportunityCandidateStatus::Monitoring;
    }

    public function markDuplicate(string $reason): void
    {
        $this->assertNotTerminal('be marked duplicate');
        $this->qualificationReason = self::required($reason, 'duplicate reason');
        $this->status = OpportunityCandidateStatus::Duplicate;
    }

    public function expire(string $reason): void
    {
        $this->assertNotTerminal('expire');

        if ($this->status === OpportunityCandidateStatus::HandedOff) {
            throw new DomainException('Handed-off Growth candidate cannot expire inside Growth.');
        }

        $this->qualificationReason = self::required($reason, 'expiration reason');
        $this->status = OpportunityCandidateStatus::Expired;
    }

    public function prepareHandoff(
        string $expectedValue,
        string $recommendedPlay,
        string $recommendedAction,
    ): void {
        $this->assertStatus([OpportunityCandidateStatus::Qualified], 'be prepared for handoff');

        if ($this->rationale === null || $this->score === null) {
            throw new DomainException('Growth candidate requires rationale and score before handoff.');
        }

        $this->expectedValue = self::required($expectedValue, 'expected value');
        $this->recommendedPlay = self::required($recommendedPlay, 'recommended play');
        $this->recommendedAction = self::required($recommendedAction, 'recommended action');
        $this->status = OpportunityCandidateStatus::ReadyForHandoff;
    }

    public function markHandedOff(): void
    {
        $this->assertStatus([OpportunityCandidateStatus::ReadyForHandoff], 'be handed off');
        $this->status = OpportunityCandidateStatus::HandedOff;
    }

    public function markRejectedByTargetDomain(string $reason): void
    {
        $this->assertStatus([OpportunityCandidateStatus::HandedOff], 'be rejected by target Domain');
        $this->qualificationReason = self::required($reason, 'handoff rejection reason');
        $this->status = OpportunityCandidateStatus::RejectedByTargetDomain;
    }

    /** @param list<OpportunityCandidateStatus> $allowed */
    private function assertStatus(array $allowed, string $operation): void
    {
        if (!in_array($this->status, $allowed, true)) {
            throw new DomainException(sprintf(
                'Growth candidate in status %s cannot %s.',
                $this->status->value,
                $operation,
            ));
        }
    }

    private function assertNotTerminal(string $operation): void
    {
        if ($this->status->isTerminal()) {
            throw new DomainException(sprintf(
                'Terminal Growth candidate in status %s cannot %s.',
                $this->status->value,
                $operation,
            ));
        }
    }

    private static function required(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Growth candidate %s is required.', $field));
        }
        return $value;
    }

    /**
     * @param list<string> $references
     * @return list<string>
     */
    private static function references(array $references, string $kind): array
    {
        $normalized = [];
        foreach ($references as $reference) {
            $reference = trim($reference);
            if ($reference === '') {
                throw new InvalidArgumentException(sprintf('Growth candidate %s reference must not be empty.', $kind));
            }
            $normalized[$reference] = true;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(sprintf('Growth candidate requires at least one %s reference.', $kind));
        }

        return array_keys($normalized);
    }
}
