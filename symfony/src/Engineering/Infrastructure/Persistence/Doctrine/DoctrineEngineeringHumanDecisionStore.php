<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Persistence\Doctrine\Entity\Engineering\HumanDecisionRequestRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEngineeringHumanDecisionStore implements EngineeringHumanDecisionStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function create(
        string $featureId,
        string $workflowId,
        string $type,
        string $question,
        string $reason,
        array $options,
        array $evidence,
        bool $blocking = true,
        ?string $recommendedOption = null,
    ): string {
        $id = EngineeringId::generate();
        $this->entityManager->persist(new HumanDecisionRequestRecord(
            id: $id,
            featureId: $featureId,
            workflowExecutionId: $workflowId,
            type: $type,
            question: $question,
            reason: $reason,
            options: $options,
            evidence: $evidence,
            blocking: $blocking,
            status: 'OPEN',
            createdAt: new DateTimeImmutable(),
            recommendedOption: $recommendedOption,
        ));
        $this->entityManager->flush();
        return $id;
    }

    public function openForFeature(string $featureId): array
    {
        $records = $this->entityManager->getRepository(HumanDecisionRequestRecord::class)->findBy(
            ['featureId' => $featureId, 'status' => 'OPEN'],
            ['createdAt' => 'ASC'],
        );

        return array_map(static fn (HumanDecisionRequestRecord $record): array => [
            'id' => $record->id(),
            'workflow_id' => $record->workflowExecutionId(),
            'question' => $record->question(),
            'reason' => $record->reason(),
            'options' => $record->options(),
            'blocking' => $record->blocking(),
            'status' => $record->status(),
            'recommended_option' => $record->recommendedOption(),
        ], $records);
    }
}
