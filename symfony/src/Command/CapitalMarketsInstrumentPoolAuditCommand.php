<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Service\InstrumentPoolCatalogAudit;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:instrument-pool:audit',
    description:'Read-only live venue catalog and canonical mapping drift report.')]
final class CapitalMarketsInstrumentPoolAuditCommand extends Command
{
    public function __construct(private readonly InstrumentPoolCatalogAudit $audit)
    {parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization')
            ->addArgument('provider',InputArgument::REQUIRED,'BINANCE / BYBIT / KRAKEN')
            ->addArgument('venue',InputArgument::REQUIRED,'Canonical venue ID')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum diff rows','200');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $limit=filter_var($input->getOption('limit'),FILTER_VALIDATE_INT);
        if($limit===false||$limit<1||$limit>500){
            $output->writeln('INVALID_LIMIT');return Command::INVALID;
        }
        try{
            $result=$this->audit->inspect(
                (string)$input->getArgument('organization'),
                (string)$input->getArgument('provider'),
                (string)$input->getArgument('venue'),$limit,
            );
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR));
            return $result['health']==='AVAILABLE'?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            $output->writeln(json_encode([
                'status'=>'FAILED','error_type'=>get_class($error),
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
