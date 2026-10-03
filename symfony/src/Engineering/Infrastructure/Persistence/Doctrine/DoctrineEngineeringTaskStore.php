<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
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

            $this->entityManager->persist(new EngineeringTaskRecord(
                id: EngineeringId::generate(),
                featureId: $featureId,
                externalKey: $externalKey,
                type: strtoupper((string) ($task['type'] ?? 'RESEARCH')),
                title: trim((string) ($task['title'] ?? $externalKey)),
                description: trim((string) ($task['description'] ?? $task['title'] ?? $externalKey)),
                status: strtoupper((string) ($task['status'] ?? 'PENDING')),
                assignedRole: strtoupper((string) ($task['assigned_role'] ?? 'DEVELOPER')),
                dependencies: is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [],
                acceptanceCriteria: is_array($task['acceptance_criteria'] ?? null) ? $task['acceptance_criteria'] : [],
                attempt: 0,
                maxAttempts: 3,
                createdAt: $now,
                updatedAt: $now,
            ));
        }
        $this->entityManager->flush();
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
                'status' => $record->status(),
                'assigned_role' => $record->assignedRole(),
            ],
            $records,
        );
    }
}
