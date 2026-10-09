<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\Sales\Application\Contract\SalesLocalTaskOutcomeReadModelInterface;
use Platform\Orchestration\Goal\GoalOutcomeEvidenceProviderInterface;

/**
 * Domain-owned evidence only for locally persisted Sales CRM task activities.
 * Never equates QUEUED/COMPLETED Action receipts to a created CRM task.
 */
final readonly class SalesLocalTaskOutcomeEvidenceProvider implements GoalOutcomeEvidenceProviderInterface
{
    public const CRITERION = 'sales.local_tasks_created';

    public function __construct(private SalesLocalTaskOutcomeReadModelInterface $tasks) {}

    public function domain(): string
    {
        return 'sales';
    }

    public function supports(string $criterionId): bool
    {
        return $criterionId === self::CRITERION;
    }

    public function observe(
        string $organizationId,
        string $criterionId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        if (!$this->supports($criterionId) || $organizationId === '' || $to <= $from) {
            throw new DomainException('Unsupported local CRM task outcome scope.');
        }
        $count = $this->tasks->createdTaskCount($organizationId, $from, $to);
        if ($count < 0) {
            throw new DomainException('Negative local CRM task count is invalid.');
        }

        $start = $from->format('Y-m-d\TH:i:s.uP');
        $end = $to->format('Y-m-d\TH:i:s.uP');
        $fingerprint = hash('sha256', implode("\0", [
            'sales.local_task_activities.v1', $organizationId, $criterionId,
            $start, $end, (string) $count,
        ]));
        return [
            'value' => $count,
            'evidence' => ['sales:local_task_activities:v1:' . $fingerprint],
            'source' => 'sales.tn_client_case_activities.task.v1',
            'window_start' => $start,
            'window_end' => $end,
        ];
    }
}
