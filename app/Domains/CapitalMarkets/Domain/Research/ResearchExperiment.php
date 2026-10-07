<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ResearchExperiment
{
    private const TYPES=[
        'DESCRIPTIVE_ANALYSIS','CORRELATION_ANALYSIS','DISTRIBUTION_ANALYSIS','EVENT_STUDY','BACKTEST',
        'PARAMETER_TEST','OUT_OF_SAMPLE','WALK_FORWARD','PAPER_FORWARD_TEST','STRESS_TEST','SENSITIVITY_ANALYSIS'
    ];

    /** @param array<string,mixed> $parameters @param array<string,mixed> $successCriteria @param array<string,mixed> $failureCriteria */
    public function __construct(
        public string $id,
        public string $hypothesisId,
        public string $title,
        public string $objective,
        public string $experimentType,
        public string $datasetId,
        public string $strategyVersionId,
        public string $executionModelVersion,
        public string $riskConfigurationVersion,
        public array $parameters,
        public DateTimeImmutable $startPeriod,
        public DateTimeImmutable $endPeriod,
        public array $successCriteria,
        public array $failureCriteria,
        public ExperimentStatus $status,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt=null,
        public ?DateTimeImmutable $completedAt=null,
    ){
        if(trim($id)===''||trim($hypothesisId)===''||trim($datasetId)===''||trim($strategyVersionId)===''||$endPeriod<=$startPeriod){
            throw new InvalidArgumentException('Invalid research experiment.');
        }
        if(!in_array($experimentType,self::TYPES,true)){
            throw new InvalidArgumentException('Invalid experiment type.');
        }
    }
}
