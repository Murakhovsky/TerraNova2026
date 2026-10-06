<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\TokenizedEquityVerticalSliceRepositoryInterface;
use Domains\CapitalMarkets\Domain\Opportunity\HypothesisCode;
use Domains\CapitalMarkets\Domain\Service\HypothesisResearchPolicy;

final readonly class TokenizedEquityResearchService
{
    public function __construct(
        private TokenizedEquityVerticalSliceRepositoryInterface $repository,
        private HypothesisResearchPolicy $policy,
    ){}

    /** @return array<string,mixed> */
    public function report(string $organizationId,int $minimumObservations=30,int $minimumRealized=10):array
    {
        $reports=[];
        foreach(HypothesisCode::cases() as $hypothesis){
            $metrics=$this->repository->researchMetrics($organizationId,$hypothesis->value);
            $reports[$hypothesis->value]=[
                ...$metrics,
                'hypothesis'=>$hypothesis->value,
                'verdict'=>$this->policy->verdict($metrics,$minimumObservations,$minimumRealized)->value,
                'minimum_observations'=>$minimumObservations,
                'minimum_realized_executions'=>$minimumRealized,
                'edge_funnel'=>[
                    'observed_scans'=>$metrics['observation_count'],
                    'detected'=>$metrics['detected_count'],
                    'executable'=>$metrics['executable_count'],
                    'realized'=>$metrics['realized_count'],
                ],
            ];
        }
        return [
            'hypotheses'=>$reports,
            'generated_at'=>(new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
