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
    public function transitionExperimentStatus(string $organizationId,string $experimentId,string $from,string $to,string $updatedAt):bool;

    public function saveStrategyVersion(string $organizationId,array $record):void;
    public function listStrategyVersions(string $organizationId,string $strategyId):array;
    public function getStrategyVersion(string $organizationId,string $strategyVersionId):?array;

    public function saveResult(string $organizationId,array $record):void;
    public function getResultForExperiment(string $organizationId,string $experimentId):?array;
    public function listResults(string $organizationId,int $limit=200):array;

    public function savePromotionDecision(string $organizationId,array $record):void;
    public function listPromotionDecisions(string $organizationId,string $strategyVersionId):array;
    public function listAllPromotionDecisions(string $organizationId,int $limit=200):array;

    public function saveBacktestRun(string $organizationId,array $record):void;
    public function listBacktestRuns(string $organizationId,int $limit=200):array;
    public function getBacktestRun(string $organizationId,string $runId):?array;
    public function saveOutOfSampleRun(string $organizationId,array $record):void;
    public function savePaperRun(string $organizationId,array $record):void;
    public function getPaperRun(string $organizationId,string $runId):?array;
    public function listPaperRuns(string $organizationId,int $limit=200):array;
    public function getOutOfSampleRun(string $organizationId,string $runId):?array;
    public function listOutOfSampleRuns(string $organizationId,int $limit=200):array;
    public function saveScorecard(string $organizationId,array $record):void;
    public function listScorecards(string $organizationId,int $limit=200):array;
    public function saveRejectedHypothesis(string $organizationId,array $record):void;
    public function listRejectedHypotheses(string $organizationId,int $limit=200):array;
    public function saveKnowledge(string $organizationId,array $record):void;
    public function listKnowledge(string $organizationId,int $limit=200):array;
}
