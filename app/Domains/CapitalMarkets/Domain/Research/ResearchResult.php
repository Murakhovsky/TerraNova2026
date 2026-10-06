<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ResearchResult
{
    /** @param array<string,mixed> $metrics @param array<string,mixed> $financialMetrics
     *  @param array<string,mixed> $riskMetrics @param array<string,mixed> $executionMetrics
     *  @param array<string,mixed> $statisticalMetrics @param array<string,mixed> $dataQuality
     *  @param list<string> $limitations @param array<string,int|float|string> $sample
     */
    public function __construct(
        public string $id,
        public string $experimentId,
        public string $status,
        public array $metrics,
        public array $financialMetrics,
        public array $riskMetrics,
        public array $executionMetrics,
        public array $statisticalMetrics,
        public array $dataQuality,
        public array $limitations,
        public string $conclusion,
        public array $sample,
        public string $executionFidelity,
        public int $confidence,
        public DateTimeImmutable $createdAt,
    ){
        if(trim($id)===''||trim($experimentId)===''){
            throw new InvalidArgumentException('Invalid research result identity.');
        }
        if(!in_array($status,['POSITIVE','NEGATIVE','MIXED','INCONCLUSIVE','INVALID','INCOMPLETE'],true)){
            throw new InvalidArgumentException('Invalid research result status.');
        }
        if(!in_array($executionFidelity,['HIGH','MEDIUM','LIMITED','SYNTHETIC'],true)){
            throw new InvalidArgumentException('Invalid execution fidelity.');
        }
        if($confidence<0||$confidence>100){
            throw new InvalidArgumentException('Confidence must be 0..100.');
        }
    }
}
