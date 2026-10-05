<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Persistence\Doctrine\Entity\Engineering\AgentArtifactRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEngineeringArtifactStore implements EngineeringArtifactStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function createVersion(
        string $featureId,
        ArtifactType $type,
        array $content,
        ?string $taskId = null,
        ?string $agentRunId = null,
        ?string $createdByAgent = null,
    ): array {
        $repository = $this->entityManager->getRepository(AgentArtifactRecord::class);
        $previous = $repository->findOneBy(
            ['featureId' => $featureId, 'type' => $type->value],
            ['version' => 'DESC'],
        );
        $version = $previous instanceof AgentArtifactRecord ? $previous->version() + 1 : 1;
        if ($previous instanceof AgentArtifactRecord && $previous->status() === 'ACTIVE') {
            $previous->supersede();
        }

        $normalized = $this->normalize($content);
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $record = new AgentArtifactRecord(
            id: EngineeringId::generate(),
            featureId: $featureId,
            type: $type->value,
            version: $version,
            status: 'ACTIVE',
            content: $content,
            contentHash: $hash,
            createdAt: new DateTimeImmutable(),
            taskId: $taskId,
            agentRunId: $agentRunId,
            supersedesArtifactId: $previous instanceof AgentArtifactRecord ? $previous->id() : null,
            createdByAgent: $createdByAgent,
        );
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $this->view($record);
    }

    public function latest(string $featureId, ArtifactType $type): ?array
    {
        $record = $this->entityManager->getRepository(AgentArtifactRecord::class)->findOneBy(
            ['featureId' => $featureId, 'type' => $type->value, 'status' => 'ACTIVE'],
            ['version' => 'DESC'],
        );
        return $record instanceof AgentArtifactRecord ? $this->view($record) : null;
    }

    private function view(AgentArtifactRecord $record): array
    {
        return [
            'id' => $record->id(),
            'type' => $record->type(),
            'version' => $record->version(),
            'status' => $record->status(),
            'content' => $record->content(),
            'content_hash' => $record->contentHash(),
            'created_at' => $record->createdAt()->format(DATE_ATOM),
            'task_id' => $record->taskId(),
            'agent_run_id' => $record->agentRunId(),
            'created_by_agent' => $record->createdByAgent(),
            'supersedes_artifact_id' => $record->supersedesArtifactId(),
        ];
    }

    private function normalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) $item = $this->normalize($item);
        }
        unset($item);
        if (!array_is_list($value)) ksort($value);
        return $value;
    }
}
