<?php
declare(strict_types=1);

namespace App\Persistence\Doctrine\Entity\Engineering;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cos_engineering_tasks')]
class EngineeringTaskRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $featureId,
        #[ORM\Column(type: Types::STRING, length: 128)]
        private string $externalKey,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $type,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $title,
        #[ORM\Column(type: Types::TEXT)]
        private string $description,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $status,
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $assignedRole,
        #[ORM\Column(type: Types::JSON)]
        private array $dependencies,
        #[ORM\Column(type: Types::JSON)]
        private array $acceptanceCriteria,
        #[ORM\Column(type: Types::INTEGER)]
        private int $attempt,
        #[ORM\Column(type: Types::INTEGER)]
        private int $maxAttempts,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $updatedAt,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $result = null,
    ) {}

    public function id(): string { return $this->id; }
    public function featureId(): string { return $this->featureId; }
    public function externalKey(): string { return $this->externalKey; }
    public function status(): string { return $this->status; }
    public function assignedRole(): string { return $this->assignedRole; }
}
