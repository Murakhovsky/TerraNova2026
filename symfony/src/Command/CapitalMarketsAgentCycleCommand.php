<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Service\CapitalMarketsRuntimeOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Manual, explicitly enabled orchestration, never a live trading daemon. */
#[AsCommand(
    name:'cos:capital-markets:agents:cycle',
    description:'Run evidence-gated Capital Markets agent team; no order execution.',
)]
final class CapitalMarketsAgentCycleCommand extends Command
{
    public function __construct(
        private readonly CapitalMarketsRuntimeOrchestrator $orchestrator,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('opportunity',InputArgument::REQUIRED,'Persisted opportunity ID')
            ->addOption('question',null,InputOption::VALUE_REQUIRED,
                'Research question','Review this opportunity using only persisted evidence.')
            ->addOption('resume',null,InputOption::VALUE_NONE,'Explicitly resume a waiting or failed cycle')
            ->addOption('correlation',null,InputOption::VALUE_REQUIRED,
                'Optional audit correlation ID');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        if(getenv('COS_CM_RUNTIME_AGENT_TEAM')!=='1'){
            $output->writeln('CAPITAL_MARKETS_AGENT_TEAM_DISABLED');
            return Command::FAILURE;
        }
        $org=trim((string)$input->getArgument('organization'));
        $opportunity=trim((string)$input->getArgument('opportunity'));
        $question=trim((string)$input->getOption('question'));
        $custom=$input->getOption('correlation');
        $correlation=is_string($custom)&&trim($custom)!==''
            ?trim($custom):'cm-agents-'.bin2hex(random_bytes(10));
        if($org===''||$opportunity===''||$question===''||strlen($question)>4000){
            $output->writeln('INVALID_ARGUMENT');
            return Command::INVALID;
        }
        try{
            $result=$this->orchestrator->run($org,$opportunity,$question,$correlation,(bool)$input->getOption('resume'));
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            return in_array($result['status']??'',[
                'REVIEW_REQUIRED','AWAITING_EXECUTION_EVIDENCE'
            ],true)?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            // No internal market positions, API secrets, LLM context or
            // vault exception messages are emitted to operator output.
            $output->writeln(json_encode([
                'status'=>'FAILED','reason_type'=>get_class($error),
                'correlation_id'=>$correlation,
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
