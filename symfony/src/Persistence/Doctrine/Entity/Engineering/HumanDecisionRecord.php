<?php
declare(strict_types=1);

namespace App\Persistence\Doctrine\Entity\Engineering;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cos_engineering_human_decisions')]
class HumanDecisionRecord
{
    public function __construct(
        #[ORM\Id] #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $requestId,
        #[ORM\Column(type: Types::STRING, length: 128)]
        private string $selectedOption,
        #[ORM\Column(type: Types::STRING, length: 128)]
        private string $decidedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $comment = null,
    ) {}
}
