<?php
declare(strict_types=1);

namespace App\Application\Diagnostic\Command;

use DateTimeImmutable;
use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class AcceptDiagnosticRecommendationCommand implements CommandInterface
{
    public const DEFAULT_WORKFLOW = 'diagnostic.recommendation.implementation';

    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $sessionId,
        public string $recommendationId,
        public int $ownerId,
        public DateTimeImmutable $dueAt,
        public string $workflowCode,
        public string $correlationId,
    ) {}
}
