<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Infrastructure\Agent\MysqlCapitalMarketsAgentCycleJournal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Read-only operator inspection. Never starts a new AgentRun or performs
 * market/capital/ledger mutations; source MySQL is the runtime truth.
 */
#[AsCommand(
    name:'cos:capital-markets:agents:status',
    description:'Inspect a durable Capital Markets agent cycle without replaying LLM stages.',
)]
final class CapitalMarketsAgentCycleStatusCommand extends Command
{
    public function __construct(
        private readonly MysqlCapitalMarketsAgentCycleJournal $journal,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('correlation',InputArgument::REQUIRED,'Existing agent cycle correlation ID');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        if(getenv('COS_CM_RUNTIME_AGENT_TEAM')!=='1'){
            $output->writeln('CAPITAL_MARKETS_AGENT_TEAM_DISABLED');
            return Command::FAILURE;
        }
        $org=trim((string)$input->getArgument('organization'));
        $corr=trim((string)$input->getArgument('correlation'));
        if($org===''||strlen($org)>190||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,96}$/D',$corr)!==1){
            $output->writeln('INVALID_ARGUMENT');
            return Command::INVALID;
        }
        try{
            $record=$this->journal->snapshot($org,$corr);
            if($record===null){
                $output->writeln('CYCLE_NOT_FOUND');
                return Command::FAILURE;
            }
            $stages=array_map(static fn(array $step):array=>[
                'stage'=>$step['stage']??null,
                'agent'=>$step['agent']??null,
                'run_id'=>$step['run_id']??null,
                'decision'=>$step['decision']??null,
            ],$record['stages']);
            $output->writeln(json_encode([
                'organization_id'=>$org,
                'correlation_id'=>$corr,
                'opportunity_id'=>$record['opportunity_id'],
                'status'=>$record['state'],
                'attempt'=>(int)$record['attempt'],
                'stage_count'=>count($stages),
                'stages'=>$stages,
                'next_action'=>$record['result']['next_action']??null,
                'updated_at'=>$record['updated_at'],
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }catch(Throwable $error){
            $output->writeln('AGENT_CYCLE_STATUS_UNAVAILABLE');
            return Command::FAILURE;
        }
    }
}
