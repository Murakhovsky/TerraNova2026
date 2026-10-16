<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\Sales\Application\Contract\SalesHistoricalMetricsReadModelInterface;
use Platform\Orchestration\Goal\GoalOutcomeEvidenceProviderInterface;

/**
 * Trusted Sales outcome evidence from the tenant-scoped canonical event
 * read model. This counts won/closed deals, NOT completed Federation Actions.
 */
final readonly class SalesClosedOutcomeEvidenceProvider implements GoalOutcomeEvidenceProviderInterface
{
    public function __construct(private SalesHistoricalMetricsReadModelInterface $readModel) {}

    public function domain(): string
    {
        return 'sales';
    }

    public function supports(string $criterionId): bool
    {
        return in_array($criterionId, ['sales.won_deals', 'sales.closed_deals'], true);
    }

    public function observe(
        string $organizationId,
        string $criterionId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        if (!$this->supports($criterionId) || $organizationId === '' || $to <= $from) {
            throw new DomainException('Unsupported Sales outcome evidence scope or period.');
        }
        $facts = $this->readModel->closedOutcomes($organizationId, $from, $to);
        $key = $criterionId === 'sales.won_deals' ? 'won' : 'closed';
        $count = $facts[$key] ?? null;
        if (!is_int($count) || $count < 0 || ($facts['closed'] ?? -1) < ($facts['won'] ?? 0)) {
            throw new DomainException('Sales outcome read model returned inconsistent counts.');
        }
        $start = $from->format('Y-m-d\TH:i:s.uP');
        $end = $to->format('Y-m-d\TH:i:s.uP');
        // Reference to a reproducible tenant/period/domain aggregate query,
        // not to a caller-supplied string or a fabricated business event.
        $queryHash = hash('sha256', implode("\0", [
            'sales.closed_outcomes.v1', $organizationId, $criterionId, $start, $end,
            (string) $count,
        ]));
        return [
            'value' => $count,
            'evidence' => ['sales:closed_outcomes:v1:' . $queryHash],
            'source' => 'sales.cos_events.closed_outcomes.v1',
            'window_start' => $start,
            'window_end' => $end,
        ];
    }
}
