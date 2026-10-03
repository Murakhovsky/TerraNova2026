<?php
declare(strict_types=1);

namespace App\Persistence\Doctrine\Entity\Engineering;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cos_engineering_workflows')]
class WorkflowExecutionRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $featureId,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $workflowType,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $currentState,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $status,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $traceId,
        #[ORM\Column(type: Types::STRING, length: 160)]
        private string $lockKey,
        #[ORM\Version] #[ORM\Column(type: Types::INTEGER)]
        private int $version,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $startedAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $lastActivityAt,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $currentTaskId = null,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $currentAgentRunId = null,
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private ?string $resumeState = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $finishedAt = null,
    ) {}

    public function id(): string { return $this->id; }
    public function currentState(): string { return $this->currentState; }
    public function moveTo(string $state): void { $this->currentState = $state; $this->lastActivityAt = new DateTimeImmutable(); }
}
