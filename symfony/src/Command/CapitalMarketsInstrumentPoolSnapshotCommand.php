<?php
declare(strict_types=1);
namespace App\Command;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolCatalogAudit;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlInstrumentPoolSnapshotRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:instrument-pool:snapshot',
    description:'Persist read-only, rate-limited provider catalog audit evidence.')]
final class CapitalMarketsInstrumentPoolSnapshotCommand extends Command
{
    public function __construct(
        private readonly InstrumentPoolCatalogAudit $audit,
        private readonly MysqlInstrumentPoolSnapshotRepository $snapshots,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('provider',InputArgument::REQUIRED,'BINANCE / BYBIT / KRAKEN')
            ->addArgument('venue',InputArgument::REQUIRED,'Canonical venue ID')
            ->addOption('actor-id',null,InputOption::VALUE_REQUIRED,'Audited operator ID')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum report rows','200');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        if(getenv('COS_CM_INSTRUMENT_POOL_SNAPSHOT_ENABLED')!=='1'){
            $output->writeln('INSTRUMENT_POOL_SNAPSHOT_DISABLED');
            return Command::FAILURE;
        }
        $actor=filter_var($input->getOption('actor-id'),FILTER_VALIDATE_INT);
        $limit=filter_var($input->getOption('limit'),FILTER_VALIDATE_INT);
        if(!is_int($actor)||$actor<1||!is_int($limit)||$limit<1||$limit>500){
            $output->writeln('INVALID_ARGUMENT');return Command::INVALID;
        }
        $org=(string)$input->getArgument('organization');
        $provider=strtoupper((string)$input->getArgument('provider'));
        $venue=(string)$input->getArgument('venue');
        try{
            if(!$this->snapshots->reserveScan($org,$provider,$venue,90)){
                $output->writeln('POOL_SCAN_COOLDOWN');return Command::FAILURE;
            }
            $snapshot=$this->audit->inspect($org,$provider,$venue,$limit);
            $id=$this->snapshots->record($org,$actor,$snapshot);
            $output->writeln(json_encode([
                'snapshot_id'=>$id,'health'=>$snapshot['health'],
                'counts'=>$snapshot['counts'],'truncated'=>$snapshot['truncated'],
                'dry_run'=>true,'executable'=>false,
            ],JSON_THROW_ON_ERROR));
            return $snapshot['health']==='AVAILABLE'?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            $output->writeln(json_encode([
                'status'=>'FAILED','error_type'=>get_class($error),
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
