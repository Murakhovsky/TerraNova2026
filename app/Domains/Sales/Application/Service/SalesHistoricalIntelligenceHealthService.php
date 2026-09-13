<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\SalesHistoricalIntelligenceHealthReadModelInterface;
use InvalidArgumentException;

final readonly class SalesHistoricalIntelligenceHealthService
{
    public function __construct(private SalesHistoricalIntelligenceHealthReadModelInterface $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function check(string $organizationId): array
    {
        $organizationId = trim($organizationId);
        if ($organizationId === '') {
            throw new InvalidArgumentException('organizationId is required.');
        }

        $snapshot = $this->readModel->snapshot($organizationId);
        $stage = (array) ($snapshot['stage'] ?? []);
        $owner = (array) ($snapshot['owner'] ?? []);
        $integrityFailures =
            (int) ($stage['missing_or_mismatched_open_projection'] ?? 0)
            + (int) ($stage['dangling_open_projection'] ?? 0)
            + (int) ($stage['duplicate_open_projection'] ?? 0)
            + (int) ($owner['missing_or_mismatched_open_projection'] ?? 0)
            + (int) ($owner['dangling_open_projection'] ?? 0)
            + (int) ($owner['duplicate_open_projection'] ?? 0);

        return [
            'organization_id' => $organizationId,
            'status' => $integrityFailures === 0 ? 'HEALTHY' : 'DEGRADED',
            'rebuild_recommended' => $integrityFailures > 0,
            'integrity_failures' => $integrityFailures,
            'stage' => $stage,
            'owner' => $owner,
            'quality_policy' => 'ESTIMATED rows reduce historical precision but do not by themselves indicate projection corruption. Missing, mismatched, dangling or duplicate open projections do.',
        ];
    }
}
