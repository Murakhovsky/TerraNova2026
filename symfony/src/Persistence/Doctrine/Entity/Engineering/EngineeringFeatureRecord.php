<?php
declare(strict_types=1);

namespace App\Persistence\Doctrine\Entity\Engineering;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cos_engineering_features')]
class EngineeringFeatureRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $title,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $type,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $status,
        #[ORM\Column(type: Types::STRING, length: 4)]
        private string $priority,
        #[ORM\Column(type: Types::JSON)]
        private array $requestPayload,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $updatedAt,
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private ?string $externalIssueId = null,
        #[ORM\Column(type: Types::STRING, length: 4, nullable: true)]
        private ?string $complexity = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $businessGoal = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $specificationSummary = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $acceptanceCriteria = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $contextMap = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $risks = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $assumptions = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $openQuestions = null,
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private ?string $repositoryRevision = null,
        #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
        private ?string $createdBy = null,
    ) {}

    public function id(): string { return $this->id; }
    public function status(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; $this->updatedAt = new DateTimeImmutable(); }
}
