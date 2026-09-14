<?php
declare(strict_types=1);

namespace Interfaces\Cli\Task;

use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesDealOwnerHistoryRebuilder;
use Domains\Sales\Application\Service\SalesDealStageHistoryRebuilder;
use Domains\Sales\Application\Service\SalesHistoricalIntelligenceHealthService;
use Domains\Sales\Application\Service\SalesMonitoringService;
use Phalcon\Cli\Task;

final class SalesTask extends Task
{
    public function monitorAction(string $organizationId, int $noActivityHours = 48, int $limit = 100): void
    {
        $organizationId = trim($organizationId);
        if ($organizationId === '') {
            throw new \InvalidArgumentException('organizationId is required.');
        }

        /** @var SalesMonitoringService $monitoring */
        $monitoring = $this->getDI()->getShared('salesMonitoringService');
        $now = new DateTimeImmutable();
        $result = [
            'organization_id' => $organizationId,
            'no_activity_detected' => $monitoring->detectNoActivity($organizationId, $now, max(1, $noActivityHours), max(1, $limit)),
            'followups_missed' => $monitoring->detectMissedFollowups($organizationId, $now, max(1, $limit)),
            'run_at' => $now->format(DATE_ATOM),
        ];

        $this->output($result);
    }

    public function rebuildHistoryAction(string $organizationId): void
    {
        $organizationId = $this->organizationId($organizationId);
        /** @var SalesDealStageHistoryRebuilder $stage */
        $stage = $this->getDI()->getShared('salesDealStageHistoryRebuilder');
        /** @var SalesDealOwnerHistoryRebuilder $owner */
        $owner = $this->getDI()->getShared('salesDealOwnerHistoryRebuilder');
        /** @var SalesHistoricalIntelligenceHealthService $health */
        $health = $this->getDI()->getShared('salesHistoricalIntelligenceHealth');

        $this->output([
            'organization_id' => $organizationId,
            'stage' => $stage->rebuildOrganization($organizationId),
            'owner' => $owner->rebuildOrganization($organizationId),
            'health' => $health->check($organizationId),
            'completed_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    public function historyHealthAction(string $organizationId): void
    {
        $organizationId = $this->organizationId($organizationId);
        /** @var SalesHistoricalIntelligenceHealthService $health */
        $health = $this->getDI()->getShared('salesHistoricalIntelligenceHealth');
        $this->output($health->check($organizationId));
    }

    private function organizationId(string $organizationId): string
    {
        $organizationId = trim($organizationId);
        if ($organizationId === '') {
            throw new \InvalidArgumentException('organizationId is required.');
        }
        return $organizationId;
    }

    /** @param array<string,mixed> $payload */
    private function output(array $payload): void
    {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}
