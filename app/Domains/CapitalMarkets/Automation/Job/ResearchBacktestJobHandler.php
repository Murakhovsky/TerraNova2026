<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Automation\Job;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Application\Service\ResearchBacktestService;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Job;
use RuntimeException;

final readonly class ResearchBacktestJobHandler implements JobHandlerInterface
{
    public const TYPE='CAPITAL_MARKETS_RESEARCH_BACKTEST';

    public function __construct(
        private ResearchBacktestService $backtests,
        private ResearchLabRepositoryInterface $repository,
    ){}

    public function supports(string $type):bool
    {
        return $type===self::TYPE;
    }

    public function handle(Job $job):void
    {
        $specification=$job->payload['specification']??null;
        if(!is_array($specification)||array_is_list($specification)){
            throw new RuntimeException('Research backtest job requires specification object.');
        }

        $runId=trim((string)($specification['run_id']??''));
        if($runId==='')throw new RuntimeException('Research backtest job requires run_id.');

        $run=$this->repository->getBacktestRun($job->organizationId,$runId);
        if(($run['status']??null)==='CANCELLED')return;

        $this->backtests->run($job->organizationId,$specification);
    }
}
