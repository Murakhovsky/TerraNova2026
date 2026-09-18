<?php
declare(strict_types=1);

namespace App\Application\Integration;

use DateTimeImmutable;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;

final readonly class IntegrationMutationAudit
{
    public function __construct(private AuditRepositoryInterface $audit)
    {
    }

    /** @param array<string,mixed> $data */
    public function user(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $action,
        string $subjectId,
        array $data = [],
    ): void {
        $this->record($organizationId, 'USER', (string) $actorId, $correlationId, $action, $subjectId, $data);
    }

    /** @param array<string,mixed> $data */
    public function integration(
        string $organizationId,
        string $provider,
        string $correlationId,
        string $action,
        string $subjectId,
        array $data = [],
    ): void {
        $this->record($organizationId, 'INTEGRATION', $provider, $correlationId, $action, $subjectId, $data);
    }

    /** @param array<string,mixed> $data */
    private function record(
        string $organizationId,
        string $actorType,
        string $actorId,
        string $correlationId,
        string $action,
        string $subjectId,
        array $data,
    ): void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),
            $organizationId,
            'INTEGRATION_MUTATION',
            $actorType,
            $actorId,
            'integration',
            $subjectId,
            null,
            ['action' => $action] + $data,
            $correlationId,
            new DateTimeImmutable(),
        ));
    }
}
