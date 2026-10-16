<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\Growth\Application\Contract\GrowthInboundResponseOutcomeReadModelInterface;
use Platform\Orchestration\Goal\GoalOutcomeEvidenceProviderInterface;

/** Read-only, verifiable Domain business fact; never an Action completion counter. */
final readonly class GrowthInboundResponseOutcomeEvidenceProvider implements GoalOutcomeEvidenceProviderInterface
{
    public const CRITERION = 'growth.inbound_responses_recorded';

    public function __construct(private GrowthInboundResponseOutcomeReadModelInterface $readModel) {}

    public function domain(): string
    {
        return 'growth';
    }

    public function supports(string $criterionId): bool
    {
        return $criterionId === self::CRITERION;
    }

    public function observe(string $organizationId, string $criterionId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!$this->supports($criterionId) || $organizationId === '' || $to <= $from) {
            throw new DomainException('Unsupported trusted evidence metric or scope.');
        }
        $count = $this->readModel->count($organizationId, $from, $to);
        if ($count < 0) {
            throw new DomainException('Negative trusted Domain metric.');
        }
        $start = $from->format('Y-m-d\TH:i:s.uP');
        $end = $to->format('Y-m-d\TH:i:s.uP');
        $hash = hash('sha256', implode("\0", [
            'growth.inbound_response_recorded.v1', $organizationId, $criterionId, $start, $end, (string) $count,
        ]));
        return [
            'value' => $count,
            'evidence' => ['growth:inbound_response:v1:' . $hash],
            'source' => 'growth.tn_growth_engagement_responses.v1',
            'window_start' => $start,
            'window_end' => $end,
        ];
    }
}
