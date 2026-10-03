<?php
declare(strict_types=1);

namespace App\Persistence\Doctrine\Entity\Engineering;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cos_engineering_findings')]
class EngineeringFindingRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $featureId,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $sourceRole,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $category,
        #[ORM\Column(type: Types::STRING, length: 16)]
        private string $severity,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $title,
        #[ORM\Column(type: Types::TEXT)]
        private string $description,
        #[ORM\Column(type: Types::JSON)]
        private array $evidence,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $status,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $taskId = null,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $agentRunId = null,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $resolvedByRunId = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $resolvedAt = null,
    ) {}
}
