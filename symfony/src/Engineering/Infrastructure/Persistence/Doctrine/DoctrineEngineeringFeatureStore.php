<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Persistence\Doctrine\Entity\Engineering\EngineeringFeatureRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineEngineeringFeatureStore implements EngineeringFeatureStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function create(string $featureId, string $organizationId, EngineeringRequest $request, ?string $createdBy = null): void
    {
        $now = new DateTimeImmutable();
        $this->entityManager->persist(new EngineeringFeatureRecord(
            id: $featureId,
            organizationId: $organizationId,
            title: $request->title ?? mb_substr($request->description, 0, 255),
            type: 'FEATURE',
            status: 'NEW',
            priority: $request->priority,
            requestPayload: [
                'request_id' => $request->requestId,
                'description' => $request->description,
                'title' => $request->title,
                'source_type' => $request->sourceType,
                'source_reference' => $request->sourceReference,
                'priority' => $request->priority,
                'metadata' => $request->metadata,
                'constraints' => $request->constraints,
                'attachments' => $request->attachments,
                'previous_context' => $request->previousContext,
            ],
            createdAt: $now,
            updatedAt: $now,
            externalIssueId: $request->sourceType === 'github_issue' ? $request->sourceReference : null,
            createdBy: $createdBy,
        ));
        $this->entityManager->flush();
    }

    public function request(string $featureId): EngineeringRequest
    {
        $record = $this->record($featureId);
        $payload = $record->requestPayload();

        return new EngineeringRequest(
            requestId: (string) ($payload['request_id'] ?? $featureId),
            description: (string) ($payload['description'] ?? ''),
            title: isset($payload['title']) ? (string) $payload['title'] : null,
            sourceType: (string) ($payload['source_type'] ?? 'user'),
            sourceReference: isset($payload['source_reference']) ? (string) $payload['source_reference'] : null,
            priority: (string) ($payload['priority'] ?? 'P2'),
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            constraints: is_array($payload['constraints'] ?? null) ? $payload['constraints'] : [],
            attachments: is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [],
            previousContext: is_array($payload['previous_context'] ?? null) ? $payload['previous_context'] : [],
        );
    }

    public function updateStatus(string $featureId, string $status): void
    {
        $this->record($featureId)->setStatus($status);
        $this->entityManager->flush();
    }

    public function updateRequest(string $featureId, string $title, string $description, string $priority): void
    {
        $this->record($featureId)->updateRequest($title, $description, $priority);
        $this->entityManager->flush();
    }

    public function appendPreviousContext(string $featureId, array $context): void
    {
        $this->record($featureId)->appendPreviousContext($context);
        $this->entityManager->flush();
    }

    public function delete(string $featureId): void
    {
        $this->entityManager->remove($this->record($featureId));
        $this->entityManager->flush();
    }

    public function applyManagerAnalysis(string $featureId, array $specification, array $contextMap, ?string $repositoryRevision): void
    {
        $this->record($featureId)->applyAnalysis($specification, $contextMap, $repositoryRevision);
        $this->entityManager->flush();
    }

    public function view(string $featureId): array
    {
        $record = $this->record($featureId);
        $payload = $record->requestPayload();

        return [
            'id' => $record->id(),
            'organization_id' => $record->organizationId(),
            'title' => $record->title(),
            'type' => $record->type(),
            'status' => $record->status(),
            'priority' => $record->priority(),
            'description' => (string) ($payload['description'] ?? ''),
            'external_issue_id' => $record->externalIssueId(),
            'repository_revision' => $record->repositoryRevision(),
            'complexity' => $record->complexity(),
            'business_goal' => $record->businessGoal(),
            'risks' => $record->risks(),
            'assumptions' => $record->assumptions(),
            'previous_context' => is_array($payload['previous_context'] ?? null) ? $payload['previous_context'] : [],
        ];
    }

    public function recentForOrganization(string $organizationId, int $limit = 50): array
    {
        $records = $this->entityManager->getRepository(EngineeringFeatureRecord::class)->findBy(
            ['organizationId' => $organizationId],
            ['updatedAt' => 'DESC'],
            max(1, min(100, $limit)),
        );

        return array_map(static fn (EngineeringFeatureRecord $record): array => [
            'id' => $record->id(),
            'title' => $record->title(),
            'type' => $record->type(),
            'status' => $record->status(),
            'priority' => $record->priority(),
            'complexity' => $record->complexity(),
            'external_issue_id' => $record->externalIssueId(),
            'repository_revision' => $record->repositoryRevision(),
            'created_at' => $record->createdAt()->format(DATE_ATOM),
            'updated_at' => $record->updatedAt()->format(DATE_ATOM),
        ], $records);
    }

    private function record(string $featureId): EngineeringFeatureRecord
    {
        $record = $this->entityManager->find(EngineeringFeatureRecord::class, $featureId);
        if (!$record instanceof EngineeringFeatureRecord) throw new RuntimeException('Engineering feature not found: '.$featureId);
        return $record;
    }
}
