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
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $organizationId,
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
    public function organizationId(): string { return $this->organizationId; }
    public function title(): string { return $this->title; }
    public function type(): string { return $this->type; }
    public function status(): string { return $this->status; }
    public function priority(): string { return $this->priority; }
    public function requestPayload(): array { return $this->requestPayload; }
    public function externalIssueId(): ?string { return $this->externalIssueId; }
    public function repositoryRevision(): ?string { return $this->repositoryRevision; }
    public function complexity(): ?string { return $this->complexity; }
    public function businessGoal(): ?string { return $this->businessGoal; }
    public function risks(): array { return $this->risks ?? []; }
    public function assumptions(): array { return $this->assumptions ?? []; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function setStatus(string $status): void { $this->status = $status; $this->updatedAt = new DateTimeImmutable(); }

    public function applyAnalysis(array $specification, array $contextMap, ?string $repositoryRevision): void
    {
        $feature = is_array($specification['feature'] ?? null) ? $specification['feature'] : [];
        $this->businessGoal = isset($feature['business_goal']) ? (string) $feature['business_goal'] : $this->businessGoal;
        $this->complexity = isset($feature['complexity']) ? (string) $feature['complexity'] : $this->complexity;
        $this->specificationSummary = $feature;
        $this->acceptanceCriteria = is_array($feature['acceptance_criteria'] ?? null) ? $feature['acceptance_criteria'] : [];
        $this->contextMap = $contextMap;
        $this->risks = is_array($specification['risks'] ?? null) ? $specification['risks'] : [];
        $this->assumptions = is_array($specification['assumptions'] ?? null) ? $specification['assumptions'] : [];
        $this->openQuestions = is_array($specification['open_questions'] ?? null) ? $specification['open_questions'] : [];
        $this->repositoryRevision = $repositoryRevision;
        $this->updatedAt = new DateTimeImmutable();
    }
}
