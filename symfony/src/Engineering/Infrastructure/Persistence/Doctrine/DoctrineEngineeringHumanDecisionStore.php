<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Persistence\Doctrine\Entity\Engineering\HumanDecisionRecord;
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

    public function get(string $requestId): array
    {
        $record = $this->entityManager->find(HumanDecisionRequestRecord::class, $requestId);
        if (!$record instanceof HumanDecisionRequestRecord) {
            throw new \RuntimeException('Engineering human decision request not found: '.$requestId);
        }
        return $this->view($record);
    }

    public function answer(string $requestId, string $selectedOption, ?string $comment, string $decidedBy): array
    {
        $record = $this->entityManager->find(HumanDecisionRequestRecord::class, $requestId);
        if (!$record instanceof HumanDecisionRequestRecord) {
            throw new \RuntimeException('Engineering human decision request not found: '.$requestId);
        }
        if ($record->status() !== 'OPEN') {
            throw new \LogicException('Engineering human decision request is not open.');
        }
        if (trim($selectedOption) === '' || trim($decidedBy) === '') {
            throw new \InvalidArgumentException('Human decision option and actor are required.');
        }

        $decisionId = EngineeringId::generate();
        $this->entityManager->persist(new HumanDecisionRecord(
            id: $decisionId,
            requestId: $requestId,
            selectedOption: $selectedOption,
            decidedBy: $decidedBy,
            createdAt: new DateTimeImmutable(),
            comment: $comment,
        ));
        $record->markAnswered(new DateTimeImmutable());
        $this->entityManager->flush();

        return [
            'feature_id' => $record->featureId(),
            'workflow_id' => $record->workflowExecutionId(),
            'decision_id' => $decisionId,
        ];
    }

    public function openForFeature(string $featureId): array
    {
        $records = $this->entityManager->getRepository(HumanDecisionRequestRecord::class)->findBy(
            ['featureId' => $featureId, 'status' => 'OPEN'],
            ['createdAt' => 'ASC'],
        );

        return array_map(fn (HumanDecisionRequestRecord $record): array => $this->view($record), $records);
    }

    private function view(HumanDecisionRequestRecord $record): array
    {
        return [
            'id' => $record->id(),
            'feature_id' => $record->featureId(),
            'workflow_id' => $record->workflowExecutionId(),
            'type' => $record->type(),
            'question' => $record->question(),
            'reason' => $record->reason(),
            'options' => $record->options(),
            'evidence' => $record->evidence(),
            'blocking' => $record->blocking(),
            'status' => $record->status(),
            'recommended_option' => $record->recommendedOption(),
        ];
    }
}
