<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\TokenizedEquityVerticalSliceRepositoryInterface;
use Domains\CapitalMarkets\Domain\Research\HypothesisResearchEngine;
use InvalidArgumentException;

final readonly class TokenizedEquityResearchService
{
    public function __construct(
        private TokenizedEquityVerticalSliceRepositoryInterface $repository,
        private HypothesisResearchEngine $engine,
    ){}

    /** @return array<string,mixed> */
    public function summary(string $organizationId,?string $hypothesis=null,int $minimumDetectedSample=30,int $minimumPaperSample=10):array
    {
        $hypotheses=$hypothesis===null?['H1','H2']:[strtoupper(trim($hypothesis))];
        foreach($hypotheses as $code){
            if(!in_array($code,['H1','H2'],true))throw new InvalidArgumentException('hypothesis must be H1 or H2.');
        }

        $result=[];
        foreach($hypotheses as $code){
            $observations=$this->repository->listHypothesisObservations($organizationId,$code,10000);
            $result[$code]=$this->engine->summarize($observations,$minimumDetectedSample,$minimumPaperSample);
            $result[$code]['observation_count']=count($observations);
        }

        return ['hypotheses'=>$result];
    }

    /** @return array<string,mixed> */
    public function replay(string $organizationId,string $hypothesis,int $minimumDetectedSample=30,int $minimumPaperSample=10):array
    {
        $code=strtoupper(trim($hypothesis));
        if(!in_array($code,['H1','H2'],true))throw new InvalidArgumentException('hypothesis must be H1 or H2.');
        $observations=$this->repository->listHypothesisObservations($organizationId,$code,10000);
        $summary=$this->engine->summarize($observations,$minimumDetectedSample,$minimumPaperSample);

        $canonical=array_map(static function(array $row):array{
            ksort($row);
            return $row;
        },$observations);

        return [
            'hypothesis'=>$code,
            'observation_count'=>count($observations),
            'dataset_hash'=>hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)),
            'summary'=>$summary,
            'mode'=>'DETERMINISTIC_RESEARCH_REPLAY',
            'mutated'=>false,
        ];
    }
}
