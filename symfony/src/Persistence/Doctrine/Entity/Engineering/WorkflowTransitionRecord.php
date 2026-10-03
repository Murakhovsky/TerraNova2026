<?php
declare(strict_types=1);

namespace App\Persistence\Doctrine\Entity\Engineering;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cos_engineering_transitions')]
class WorkflowTransitionRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $workflowExecutionId,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $featureId,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $fromState,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $toState,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $trigger,
        #[ORM\Column(type: Types::TEXT)]
        private string $reason,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $initiatedByType,
        #[ORM\Column(type: Types::STRING, length: 128)]
        private string $initiatedById,
        #[ORM\Column(type: Types::JSON)]
        private array $metadata,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $agentRunId = null,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $humanDecisionId = null,
    ) {}
}
