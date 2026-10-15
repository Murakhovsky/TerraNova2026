<?php
declare(strict_types=1);
namespace App\Command;

use Domains\CapitalMarkets\Application\Service\InstrumentPoolExternalAttestations;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolProductionReadiness;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Read-only default; evidence mutations require separated authorized people. */
#[AsCommand(name:'cos:capital-markets:instrument-pool:production',
    description:'Read-only readiness, or two-person document attestations; never enables trading.')]
final class CapitalMarketsInstrumentPoolProductionCommand extends Command
{
    public function __construct(
        private readonly InstrumentPoolProductionReadiness $readiness,
        private readonly InstrumentPoolExternalAttestations $documents,
    ){parent::__construct();}
    protected function configure():void
    {
        $this->addArgument('action',InputArgument::REQUIRED,'status|propose|approve|reject')
            ->addArgument('organization',InputArgument::REQUIRED,'Tenant')
            ->addOption('actor',null,InputOption::VALUE_REQUIRED,'Audited actor')
            ->addOption('kind',null,InputOption::VALUE_REQUIRED,'Document kind')
            ->addOption('subject',null,InputOption::VALUE_REQUIRED,'Source or instrument ID')
            ->addOption('url',null,InputOption::VALUE_REQUIRED,'Stable HTTPS document URL, no secrets')
            ->addOption('sha256',null,InputOption::VALUE_REQUIRED,'Document SHA-256')
            ->addOption('valid-until',null,InputOption::VALUE_REQUIRED,'UTC yyyy-mm-dd HH:mm:ss')
            ->addOption('id',null,InputOption::VALUE_REQUIRED,'Pending ID');
    }
    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $action=(string)$input->getArgument('action');
        $org=(string)$input->getArgument('organization');
        try{
            if($action==='status'){
                $state=$this->readiness->report($org);
                $output->writeln(json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
                return $state['internal_readiness']?Command::SUCCESS:Command::FAILURE;
            }
            $actor=filter_var($input->getOption('actor'),FILTER_VALIDATE_INT);
            if(!is_int($actor)||$actor<1)throw new \DomainException('Positive actor required.');
            if($action==='propose'){
                $id=$this->documents->propose($org,$actor,
                    (string)$input->getOption('kind'),(string)$input->getOption('subject'),
                    (string)$input->getOption('url'),(string)$input->getOption('sha256'),
                    (string)$input->getOption('valid-until'));
                $output->writeln(json_encode(['status'=>'PENDING','id'=>$id],JSON_THROW_ON_ERROR));
                return Command::SUCCESS;
            }
            if(in_array($action,['approve','reject'],true)){
                $id=filter_var($input->getOption('id'),FILTER_VALIDATE_INT);
                if(!is_int($id)||$id<1)throw new \DomainException('Positive evidence ID required.');
                $this->documents->decide($org,$actor,$id,$action==='approve');
                $output->writeln(json_encode(['status'=>$action==='approve'?'APPROVED':'REJECTED','id'=>$id],JSON_THROW_ON_ERROR));
                return Command::SUCCESS;
            }
            throw new \DomainException('Only status, propose, approve or reject accepted.');
        }catch(Throwable $e){
            $output->writeln(json_encode(['status'=>'BLOCKED','error_type'=>get_class($e)],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
