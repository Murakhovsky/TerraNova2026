<?php
declare(strict_types=1);

namespace App\Persistence\Doctrine\Entity\Engineering;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cos_engineering_human_decision_requests')]
class HumanDecisionRequestRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $featureId,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $workflowExecutionId,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $type,
        #[ORM\Column(type: Types::TEXT)]
        private string $question,
        #[ORM\Column(type: Types::TEXT)]
        private string $reason,
        #[ORM\Column(type: Types::JSON)]
        private array $options,
        #[ORM\Column(type: Types::JSON)]
        private array $evidence,
        #[ORM\Column(type: Types::BOOLEAN)]
        private bool $blocking,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $status,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
        private ?string $recommendedOption = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $resolvedAt = null,
    ) {}
}
