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
    public function report(
        string $organizationId,
        int $minimumObservations=30,
        int $minimumExecutionAttempts=10,
        string $minimumCompletionRate='0.5',
    ):array{
        $reports=[];
        foreach(HypothesisCode::cases() as $hypothesis){
            $metrics=$this->repository->researchMetrics($organizationId,$hypothesis->value);
            $reports[$hypothesis->value]=[
                ...$metrics,
                'hypothesis'=>$hypothesis->value,
                'verdict'=>$this->policy->verdict(
                    $metrics,$minimumObservations,$minimumExecutionAttempts,$minimumCompletionRate
                )->value,
                'minimum_observations'=>$minimumObservations,
                'minimum_execution_attempts'=>$minimumExecutionAttempts,
                'minimum_completion_rate'=>$minimumCompletionRate,
                'edge_funnel'=>[
                    'observed_scans'=>$metrics['observation_count'],
                    'unobservable_scans'=>$metrics['unobservable_count'],
                    'detected'=>$metrics['detected_count'],
                    'executable'=>$metrics['executable_count'],
                    'attempted'=>$metrics['execution_attempt_count'],
                    'completed'=>$metrics['completed_execution_count'],
                    'invalidated'=>$metrics['invalidated_execution_count'],
                ],
            ];
        }
        return [
            'hypotheses'=>$reports,
            'generated_at'=>(new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
