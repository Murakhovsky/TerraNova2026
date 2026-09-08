<?php
declare(strict_types=1);

namespace Interfaces\Cli\Task;

use DateTimeImmutable;
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

        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
