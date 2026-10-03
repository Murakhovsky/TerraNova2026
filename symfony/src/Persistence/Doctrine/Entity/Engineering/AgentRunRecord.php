<?php
declare(strict_types=1);

namespace App\Persistence\Doctrine\Entity\Engineering;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cos_engineering_agent_runs')]
class AgentRunRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $featureId,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $workflowExecutionId,
        #[ORM\Column(type: Types::STRING, length: 128)]
        private string $agentId,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $agentRole,
        #[ORM\Column(type: Types::STRING, length: 190, unique: true)]
        private string $idempotencyKey,
        #[ORM\Column(type: Types::STRING, length: 80)]
        private string $modelProvider,
        #[ORM\Column(type: Types::STRING, length: 160)]
        private string $model,
        #[ORM\Column(type: Types::JSON)]
        private array $inputSnapshot,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $status,
        #[ORM\Column(type: Types::INTEGER)]
        private int $technicalRetry,
        #[ORM\Column(type: Types::INTEGER)]
        private int $logicalAttempt,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $traceId,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $startedAt,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $taskId = null,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $parentRunId = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $output = null,
        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        private ?int $tokensInput = null,
        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        private ?int $tokensOutput = null,
        #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 6, nullable: true)]
        private ?string $estimatedCost = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $finishedAt = null,
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private ?string $errorType = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $errorMessage = null,
    ) {}

    public function id(): string { return $this->id; }
    public function featureId(): string { return $this->featureId; }
    public function workflowExecutionId(): string { return $this->workflowExecutionId; }
    public function agentRole(): string { return $this->agentRole; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function status(): string { return $this->status; }
    public function output(): ?array { return $this->output; }
    public function modelProvider(): string { return $this->modelProvider; }
    public function model(): string { return $this->model; }

    public function complete(
        string $status,
        array $output,
        string $provider,
        string $model,
        ?int $tokensInput,
        ?int $tokensOutput,
        ?string $estimatedCost,
        ?string $errorType,
        ?string $errorMessage,
    ): void {
        $this->status = $status;
        $this->output = $output;
        $this->modelProvider = $provider;
        $this->model = $model;
        $this->tokensInput = $tokensInput;
        $this->tokensOutput = $tokensOutput;
        $this->estimatedCost = $estimatedCost;
        $this->errorType = $errorType;
        $this->errorMessage = $errorMessage;
        $this->finishedAt = new DateTimeImmutable();
    }

    public function fail(string $errorType, string $errorMessage): void
    {
        $this->status = 'FAILED';
        $this->errorType = $errorType;
        $this->errorMessage = $errorMessage;
        $this->finishedAt = new DateTimeImmutable();
    }
}
