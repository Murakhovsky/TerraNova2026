<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Persistence\Doctrine\Entity\Engineering\EngineeringTaskRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEngineeringTaskStore implements EngineeringTaskStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function createFromManager(string $featureId, array $tasks): void
    {
        $now = new DateTimeImmutable();
        foreach (array_values($tasks) as $index => $task) {
            if (!is_array($task)) continue;
            $externalKey = trim((string) ($task['id'] ?? ''));
            if ($externalKey === '') $externalKey = sprintf('TASK-%03d', $index + 1);

            $type = strtoupper((string) ($task['type'] ?? 'RESEARCH'));
            $title = trim((string) ($task['title'] ?? $externalKey));
            $description = trim((string) ($task['description'] ?? $task['title'] ?? $externalKey));
            $candidateRole = strtoupper((string) ($task['assigned_role'] ?? 'DEVELOPER'));
            $assignedRole = AgentRole::tryFrom($candidateRole)?->value ?? AgentRole::DEVELOPER->value;
            $dependencies = is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [];
            $acceptanceCriteria = is_array($task['acceptance_criteria'] ?? null) ? $task['acceptance_criteria'] : [];

            $existing = $this->entityManager->getRepository(EngineeringTaskRecord::class)->findOneBy([
                'featureId' => $featureId,
                'externalKey' => $externalKey,
            ]);
            if ($existing instanceof EngineeringTaskRecord) {
                $existing->refresh($type, $title, $description, $assignedRole, $dependencies, $acceptanceCriteria);
                continue;
            }

            $this->entityManager->persist(new EngineeringTaskRecord(
                id: EngineeringId::generate(),
                featureId: $featureId,
                externalKey: $externalKey,
                type: $type,
                title: $title,
                description: $description,
                status: 'PENDING',
                assignedRole: $assignedRole,
                dependencies: $dependencies,
                acceptanceCriteria: $acceptanceCriteria,
                attempt: 0,
                maxAttempts: 3,
                createdAt: $now,
                updatedAt: $now,
            ));
        }
        $this->entityManager->flush();
    }

    public function markRole(string $featureId, AgentRole $role, string $status, ?array $result = null): void
    {
        $records = $this->entityManager->getRepository(EngineeringTaskRecord::class)->findBy([
            'featureId' => $featureId,
            'assignedRole' => $role->value,
        ]);
        foreach ($records as $record) {
            if ($record instanceof EngineeringTaskRecord) $record->setStatus($status, $result);
        }
        $this->entityManager->flush();
    }

    public function hasIncomplete(string $featureId): bool
    {
        foreach ($this->forFeature($featureId) as $task) {
            if (!in_array((string) ($task['status'] ?? ''), ['COMPLETED','CANCELLED'], true)) return true;
        }
        return false;
    }

    public function forFeature(string $featureId): array
    {
        $records = $this->entityManager->getRepository(EngineeringTaskRecord::class)->findBy(
            ['featureId' => $featureId],
            ['externalKey' => 'ASC'],
        );
        return array_map(
            static fn (EngineeringTaskRecord $record): array => [
                'id' => $record->id(),
                'external_key' => $record->externalKey(),
                'type' => $record->type(),
                'title' => $record->title(),
                'description' => $record->description(),
                'status' => $record->status(),
                'assigned_role' => $record->assignedRole(),
                'dependencies' => $record->dependencies(),
                'acceptance_criteria' => $record->acceptanceCriteria(),
                'attempt' => $record->attempt(),
                'max_attempts' => $record->maxAttempts(),
                'result' => $record->result(),
                'created_at' => $record->createdAt()->format(DATE_ATOM),
                'updated_at' => $record->updatedAt()->format(DATE_ATOM),
            ],
            $records,
        );
    }
}
