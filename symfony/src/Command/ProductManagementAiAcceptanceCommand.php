<?php
declare(strict_types=1);

namespace App\Command;

use App\Persistence\ProductManagement\ProductAiAcceptanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:product:ai-acceptance',description:'Supervised isolated staging P6 live AI proposal and native outcome readback.')]
final class ProductManagementAiAcceptanceCommand extends Command
{
    public function __construct(private readonly ProductAiAcceptanceService $acceptance){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('operation',InputArgument::REQUIRED,'propose or inspect')
            ->addOption('organization',null,InputOption::VALUE_REQUIRED,'Explicit isolated staging tenant')
            ->addOption('intent-id',null,InputOption::VALUE_REQUIRED)
            ->addOption('run-id',null,InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=(string)$input->getOption('organization');
        if(getenv('COS_PRODUCT_AI_ACCEPTANCE_ENABLED')!=='1'
            ||getenv('COS_PRODUCT_AI_ACCEPTANCE_ENVIRONMENT')!=='staging'
            ||getenv('COS_AGENT_LLM_MODE')==='fixture'
            ||$org===''||getenv('COS_PRODUCT_AI_ACCEPTANCE_ORGANIZATION')!==$org||$org==='default'){
            $output->writeln('{"status":"DISABLED_OR_UNAPPROVED_TENANT"}');
            return Command::FAILURE;
        }
        $operation=$input->getArgument('operation');
        if($operation==='propose'&&($input->getOption('intent-id')!==null||$input->getOption('run-id')!==null)){
            $output->writeln('{"status":"INVALID_ARGUMENTS"}');return Command::INVALID;
        }
        try{
            $result=match($input->getArgument('operation')){
                'propose'=>$this->acceptance->propose($org,(string)getenv('COS_AI_CAPABILITY_INTENT_SECRET')),
                'inspect'=>$this->acceptance->inspect($org,(string)$input->getOption('intent-id'),(string)$input->getOption('run-id')),
                default=>throw new \DomainException('Invalid operation.'),
            };
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }catch(Throwable){
            // No raw model output, provider response/credential or SQL error is ever emitted.
            $output->writeln('{"status":"BLOCKED","reason":"live_provider_tenant_intent_or_native_evidence_not_ready"}');
            return Command::FAILURE;
        }
    }
}
