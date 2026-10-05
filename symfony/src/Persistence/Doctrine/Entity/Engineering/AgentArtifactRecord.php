<?php
declare(strict_types=1);

namespace App\Persistence\Doctrine\Entity\Engineering;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cos_engineering_artifacts')]
class AgentArtifactRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $featureId,
        #[ORM\Column(type: Types::STRING, length: 48)]
        private string $type,
        #[ORM\Column(type: Types::INTEGER)]
        private int $version,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $status,
        #[ORM\Column(type: Types::JSON)]
        private array $content,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $contentHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $taskId = null,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $agentRunId = null,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $supersedesArtifactId = null,
        #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
        private ?string $createdByAgent = null,
    ) {}

    public function id(): string { return $this->id; }
    public function featureId(): string { return $this->featureId; }
    public function type(): string { return $this->type; }
    public function version(): int { return $this->version; }
    public function status(): string { return $this->status; }
    public function content(): array { return $this->content; }
    public function contentHash(): string { return $this->contentHash; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function taskId(): ?string { return $this->taskId; }
    public function agentRunId(): ?string { return $this->agentRunId; }
    public function createdByAgent(): ?string { return $this->createdByAgent; }
    public function supersedesArtifactId(): ?string { return $this->supersedesArtifactId; }
    public function supersede(): void { $this->status = 'SUPERSEDED'; }
}
