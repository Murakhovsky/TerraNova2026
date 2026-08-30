<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use DateTimeImmutable;
use DomainException;
use Domains\Diagnostic\Model\Policy\SessionCompletionPolicy;
use InvalidArgumentException;

final class DiagnosticSession
{
    private DiagnosticSessionStatus $status = DiagnosticSessionStatus::Planned;
    private ?DateTimeImmutable $startedAt = null;
    private ?DateTimeImmutable $completedAt = null;
    private int $lockVersion = 0;
    /** @var array<string, Evidence> */
    private array $evidence = [];
    /** @var array<string, DiagnosticRecord> */
    private array $records = [];

    public function __construct(
        private readonly string $id,
        private readonly string $packId,
        private readonly int $packVersion,
        private readonly DiagnosticTarget $target,
    ) {
        if (trim($id) === '' || trim($packId) === '' || $packVersion < 1) {
            throw new InvalidArgumentException('Session id/pack id must not be empty and pack version must be positive.');
        }
    }

    /**
     * @param list<Evidence> $evidence
     * @param list<DiagnosticRecord> $records
     */
    public static function rehydrate(
        string $id,
        string $packId,
        int $packVersion,
        DiagnosticTarget $target,
        DiagnosticSessionStatus $status,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $completedAt,
        int $lockVersion,
        array $evidence,
        array $records,
    ): self {
        $session = new self($id, $packId, $packVersion, $target);
        $session->status = $status;
        $session->startedAt = $startedAt;
        $session->completedAt = $completedAt;
        $session->lockVersion = $lockVersion;
        foreach ($evidence as $item) $session->evidence[$item->id] = $item;
        foreach ($records as $record) $session->records[$record->id] = $record;
        return $session;
    }

    public function start(DateTimeImmutable $startedAt): void
    {
        if ($this->status !== DiagnosticSessionStatus::Planned) {
            throw new DomainException('Only a planned diagnostic session can be started.');
        }
        $this->status = DiagnosticSessionStatus::InProgress;
        $this->startedAt = $startedAt;
        $this->lockVersion++;
    }

    public function capture(Evidence $evidence): void
    {
        $this->assertInProgress();
        if (isset($this->evidence[$evidence->id])) {
            throw new DomainException('Evidence already exists: ' . $evidence->id);
        }
        $this->evidence[$evidence->id] = $evidence;
        $this->lockVersion++;
    }

    public function record(DiagnosticRecord $record, DiagnosticPack $pack): void
    {
        $this->assertInProgress();
        if ($pack->id() !== $this->packId || $pack->version() !== $this->packVersion) {
            throw new DomainException('Session records must use the pinned diagnostic pack version.');
        }
        if (!$pack->acceptsRecord($record)) {
            throw new DomainException('Record references an unknown methodology input or criterion: ' . $record->criterionCode);
        }
        if (isset($this->records[$record->id])) {
            throw new DomainException('Diagnostic record already exists: ' . $record->id);
        }
        foreach ($record->evidenceIds as $evidenceId) {
            if (!isset($this->evidence[$evidenceId])) {
                throw new DomainException('Diagnostic record references unknown evidence: ' . $evidenceId);
            }
        }
        foreach ($record->upstreamRecordIds as $upstreamId) {
            if (!isset($this->records[$upstreamId])) {
                throw new DomainException('Diagnostic record references unknown upstream record: ' . $upstreamId);
            }
        }
        if ($record->type === DiagnosticRecordType::Recommendation) {
            $hasDiagnosticBasis = false;
            foreach ($record->upstreamRecordIds as $upstreamId) {
                if (in_array(
                    $this->records[$upstreamId]->type,
                    [DiagnosticRecordType::Finding, DiagnosticRecordType::Hypothesis],
                    true,
                )) {
                    $hasDiagnosticBasis = true;
                    break;
                }
            }
            if (!$hasDiagnosticBasis) {
                throw new DomainException('A recommendation must reference a finding or hypothesis.');
            }
        }
        $this->records[$record->id] = $record;
        $this->lockVersion++;
    }

    public function complete(
        DateTimeImmutable $completedAt,
        DiagnosticPack $pack,
        SessionCompletionPolicy $policy = new SessionCompletionPolicy(),
    ): void
    {
        $this->assertInProgress();
        $policy->assertSatisfied($this, $pack);
        $this->status = DiagnosticSessionStatus::Completed;
        $this->completedAt = $completedAt;
        $this->lockVersion++;
    }

    public function cancel(): void
    {
        if ($this->status->isTerminal()) {
            throw new DomainException('A terminal diagnostic session cannot be cancelled.');
        }
        $this->status = DiagnosticSessionStatus::Cancelled;
        $this->lockVersion++;
    }

    /** @return array<string, array{evidence:list<string>, upstream_records:list<string>}> */
    public function traceability(): array
    {
        $map = [];
        foreach ($this->records as $record) {
            $map[$record->id] = ['evidence' => $record->evidenceIds, 'upstream_records' => $record->upstreamRecordIds];
        }
        return $map;
    }

    public function id(): string { return $this->id; }
    public function packId(): string { return $this->packId; }
    public function packVersion(): int { return $this->packVersion; }
    public function target(): DiagnosticTarget { return $this->target; }
    public function status(): DiagnosticSessionStatus { return $this->status; }
    public function startedAt(): ?DateTimeImmutable { return $this->startedAt; }
    public function completedAt(): ?DateTimeImmutable { return $this->completedAt; }
    public function lockVersion(): int { return $this->lockVersion; }
    public function hasRecord(string $id): bool { return isset($this->records[$id]); }
    /** @return list<Evidence> */
    public function evidence(): array { return array_values($this->evidence); }
    /** @return list<DiagnosticRecord> */
    public function records(): array { return array_values($this->records); }

    private function assertInProgress(): void
    {
        if ($this->status !== DiagnosticSessionStatus::InProgress) {
            throw new DomainException('Diagnostic session must be in progress.');
        }
    }
}
