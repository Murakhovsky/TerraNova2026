<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalResearchValidatedOutcomeReadModelInterface;
use Platform\Orchestration\Goal\GoalOutcomeEvidenceProviderInterface;

/** Read-only, verifiable Domain business fact; never an Action completion counter. */
final readonly class CapitalResearchValidatedOutcomeEvidenceProvider implements GoalOutcomeEvidenceProviderInterface
{
    public const CRITERION = 'capital_markets.research_results_validated';

    public function __construct(private CapitalResearchValidatedOutcomeReadModelInterface $readModel) {}

    public function domain(): string
    {
        return 'capital_markets';
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
            'capital_markets.research_validated.v1', $organizationId, $criterionId, $start, $end, (string) $count,
        ]));
        return [
            'value' => $count,
            'evidence' => ['capital_markets:validated_research:v1:' . $hash],
            'source' => 'capital_markets.tn_capital_market_research_results.validated.v1',
            'window_start' => $start,
            'window_end' => $end,
        ];
    }
}
