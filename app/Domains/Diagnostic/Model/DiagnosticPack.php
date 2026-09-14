<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use DateTimeImmutable;
use DomainException;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use Domains\Diagnostic\Methodology\Serialization\MethodologyPackSerializer;
use Domains\Diagnostic\Model\Policy\PackPublicationPolicy;
use InvalidArgumentException;

final class DiagnosticPack
{
    private DiagnosticPackStatus $status = DiagnosticPackStatus::Draft;
    private ?DateTimeImmutable $publishedAt = null;
    private int $lockVersion = 0;
    private readonly string $contentHash;

    private function __construct(
        private readonly string $id,
        private readonly int $version,
        private readonly string $name,
        private readonly string $targetDomain,
        private readonly MethodologyPack $methodology,
    ) {
        if (trim($id) === '' || trim($name) === '' || $version < 1) {
            throw new InvalidArgumentException('Pack id/name must not be empty and version must be positive.');
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $targetDomain)) {
            throw new InvalidArgumentException('Pack target domain has an invalid format.');
        }
        if ($methodology->id !== $id || $methodology->version !== $version || $methodology->name !== $name) {
            throw new InvalidArgumentException('Lifecycle pack identity must match its methodology identity.');
        }
        $this->contentHash = (new MethodologyPackSerializer())->hash($methodology);
    }

    public static function draft(string $targetDomain, MethodologyPack $methodology): self
    {
        return new self($methodology->id, $methodology->version, $methodology->name, $targetDomain, $methodology);
    }

    public static function rehydrate(
        string $targetDomain,
        MethodologyPack $methodology,
        DiagnosticPackStatus $status,
        ?DateTimeImmutable $publishedAt,
        int $lockVersion,
        string $contentHash,
    ): self {
        $pack = self::draft($targetDomain, $methodology);
        if (!hash_equals($pack->contentHash, $contentHash)) {
            throw new DomainException('Stored diagnostic methodology hash does not match its content.');
        }
        $pack->status = $status;
        $pack->publishedAt = $publishedAt;
        $pack->lockVersion = $lockVersion;
        return $pack;
    }

    public function publish(DateTimeImmutable $publishedAt, PackPublicationPolicy $policy): void
    {
        $this->assertDraft();
        $policy->assertSatisfied($this->methodology);
        $this->status = DiagnosticPackStatus::Published;
        $this->publishedAt = $publishedAt;
        $this->lockVersion++;
    }

    public function revise(MethodologyPack $methodology): self
    {
        if ($this->status !== DiagnosticPackStatus::Published) {
            throw new DomainException('Only a published diagnostic pack can be revised.');
        }
        if ($methodology->id !== $this->id || $methodology->version !== $this->version + 1) {
            throw new DomainException('A methodology revision must keep the pack id and increment its version by one.');
        }
        return self::draft($this->targetDomain, $methodology);
    }

    public function retire(): void
    {
        if ($this->status !== DiagnosticPackStatus::Published) {
            throw new DomainException('Only a published diagnostic pack can be retired.');
        }
        $this->status = DiagnosticPackStatus::Retired;
        $this->lockVersion++;
    }

    public function hasCriterion(string $code): bool
    {
        foreach ($this->methodology->criteria as $criterion) if ($criterion->id === $code) return true;
        return false;
    }
    public function hasMetric(string $code): bool
    {
        foreach ($this->methodology->metrics as $metric) if ($metric->id === $code) return true;
        return false;
    }
    public function hasFactReference(string $code): bool
    {
        foreach ($this->methodology->facts as $fact) if ($fact->id === $code) return true;
        return false;
    }
    public function acceptsRecord(DiagnosticRecord $record): bool
    {
        return match ($record->type) {
            DiagnosticRecordType::Fact => $this->hasFactReference($record->criterionCode),
            DiagnosticRecordType::Metric => $this->hasMetric($record->criterionCode),
            default => $this->hasCriterion($record->criterionCode),
        };
    }
    public function id(): string { return $this->id; }
    public function version(): int { return $this->version; }
    public function name(): string { return $this->name; }
    public function targetDomain(): string { return $this->targetDomain; }
    public function status(): DiagnosticPackStatus { return $this->status; }
    public function publishedAt(): ?DateTimeImmutable { return $this->publishedAt; }
    public function methodology(): MethodologyPack { return $this->methodology; }
    public function contentHash(): string { return $this->contentHash; }
    public function lockVersion(): int { return $this->lockVersion; }

    private function assertDraft(): void
    {
        if ($this->status !== DiagnosticPackStatus::Draft) {
            throw new DomainException('Published or retired pack versions are immutable.');
        }
    }
}
