<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesAttentionRepositoryInterface;

final readonly class SalesAutomationRunner
{
    public function __construct(
        private SalesAttentionRepositoryInterface $attention,
        private SalesMonitoringService $monitoring,
    ) {
    }

    /** @return array{run_at:string,organizations:array<string,array{no_activity_detected:int,followups_overdue:int,stuck_deals:int}>} */
    public function run(
        ?string $organizationId,
        DateTimeImmutable $now,
        int $noActivityHours = 48,
        int $limit = 200,
    ): array {
        $organizationId = trim((string) $organizationId);
        $organizations = $organizationId !== ''
            ? [$organizationId]
            : $this->attention->activeOrganizations();

        $result = [
            'run_at' => $now->format(DATE_ATOM),
            'organizations' => [],
        ];

        foreach ($organizations as $organization) {
            $result['organizations'][$organization] = [
                'no_activity_detected' => $this->monitoring->detectNoActivity(
                    $organization,
                    $now,
                    max(1, $noActivityHours),
                    $limit,
                ),
                'followups_overdue' => $this->monitoring->detectMissedFollowups(
                    $organization,
                    $now,
                    $limit,
                ),
                'stuck_deals' => $this->monitoring->detectStuckDeals(
                    $organization,
                    $now,
                    $limit,
                ),
            ];
        }

        return $result;
    }
}
