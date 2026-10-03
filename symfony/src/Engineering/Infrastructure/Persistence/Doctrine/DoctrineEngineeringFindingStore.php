<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringFindingStoreInterface;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Finding\FindingCategory;
use App\Engineering\Domain\Finding\FindingSeverity;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Persistence\Doctrine\Entity\Engineering\EngineeringFindingRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEngineeringFindingStore implements EngineeringFindingStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function recordFindings(
        string $featureId,
        AgentRole $sourceRole,
        array $findings,
        ?string $agentRunId = null,
        ?string $taskId = null,
    ): void {
        $now = new DateTimeImmutable();
        foreach ($findings as $finding) {
            if (!is_array($finding)) continue;
            $severity = $this->severity((string) ($finding['severity'] ?? 'MEDIUM'));
            $category = $this->category((string) ($finding['category'] ?? 'QUALITY'));
            $title = trim((string) ($finding['title'] ?? $finding['message'] ?? 'Engineering finding'));
            $description = trim((string) ($finding['description'] ?? $finding['message'] ?? $title));

            $this->entityManager->persist(new EngineeringFindingRecord(
                id: EngineeringId::generate(),
                featureId: $featureId,
                sourceRole: $sourceRole->value,
                category: $category,
                severity: $severity,
                title: mb_substr($title !== '' ? $title : 'Engineering finding', 0, 255),
                description: $description !== '' ? $description : 'No description supplied.',
                evidence: $finding,
                status: 'OPEN',
                createdAt: $now,
                taskId: $taskId,
                agentRunId: $agentRunId,
            ));
        }
        $this->entityManager->flush();
    }

    public function resolveOpenForSource(string $featureId, AgentRole $sourceRole, string $resolvedByRunId): void
    {
        $records = $this->entityManager->getRepository(EngineeringFindingRecord::class)->findBy([
            'featureId' => $featureId,
            'sourceRole' => $sourceRole->value,
            'status' => 'OPEN',
        ]);
        $now = new DateTimeImmutable();
        foreach ($records as $record) {
            if ($record instanceof EngineeringFindingRecord) $record->resolve($resolvedByRunId, $now);
        }
        $this->entityManager->flush();
    }

    public function hasOpenCritical(string $featureId): bool
    {
        return $this->entityManager->getRepository(EngineeringFindingRecord::class)->count([
            'featureId' => $featureId,
            'severity' => FindingSeverity::CRITICAL->value,
            'status' => 'OPEN',
        ]) > 0;
    }

    public function forFeature(string $featureId): array
    {
        $records = $this->entityManager->getRepository(EngineeringFindingRecord::class)->findBy(
            ['featureId' => $featureId],
            ['createdAt' => 'ASC'],
        );

        return array_map(static fn (EngineeringFindingRecord $record): array => [
            'id' => $record->id(),
            'source_role' => $record->sourceRole(),
            'category' => $record->category(),
            'severity' => $record->severity(),
            'title' => $record->title(),
            'description' => $record->description(),
            'evidence' => $record->evidence(),
            'status' => $record->status(),
            'task_id' => $record->taskId(),
            'agent_run_id' => $record->agentRunId(),
            'resolved_by_run_id' => $record->resolvedByRunId(),
            'created_at' => $record->createdAt()->format(DATE_ATOM),
            'resolved_at' => $record->resolvedAt()?->format(DATE_ATOM),
        ], $records);
    }

    private function severity(string $value): string
    {
        $value = strtoupper(trim($value));
        return FindingSeverity::tryFrom($value)?->value ?? FindingSeverity::MEDIUM->value;
    }

    private function category(string $value): string
    {
        $value = strtoupper(trim($value));
        return FindingCategory::tryFrom($value)?->value ?? FindingCategory::QUALITY->value;
    }
}
