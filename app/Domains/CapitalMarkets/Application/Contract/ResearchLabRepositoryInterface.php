<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface ResearchLabRepositoryInterface
{
    public function saveHypothesis(string $organizationId,array $record):void;
    public function getHypothesis(string $organizationId,string $id):?array;
    public function listHypotheses(string $organizationId,int $limit=200):array;

    public function saveDataset(string $organizationId,array $record):void;
    public function getDataset(string $organizationId,string $id):?array;

    public function saveExperiment(string $organizationId,array $record):void;
    public function getExperiment(string $organizationId,string $id):?array;
    public function listExperiments(string $organizationId,?string $hypothesisId=null,int $limit=200):array;

    public function saveStrategyVersion(string $organizationId,array $record):void;
    public function listStrategyVersions(string $organizationId,string $strategyId):array;

    public function saveResult(string $organizationId,array $record):void;
    public function getResultForExperiment(string $organizationId,string $experimentId):?array;

    public function savePromotionDecision(string $organizationId,array $record):void;
    public function listPromotionDecisions(string $organizationId,string $strategyVersionId):array;

    public function saveBacktestRun(string $organizationId,array $record):void;
    public function saveOutOfSampleRun(string $organizationId,array $record):void;
    public function saveScorecard(string $organizationId,array $record):void;
    public function saveRejectedHypothesis(string $organizationId,array $record):void;
    public function saveKnowledge(string $organizationId,array $record):void;
    public function listKnowledge(string $organizationId,int $limit=200):array;
}
