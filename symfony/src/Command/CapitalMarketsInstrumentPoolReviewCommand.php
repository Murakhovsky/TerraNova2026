<?php
declare(strict_types=1);
namespace App\Command;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolLifecycleReviewer;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlInstrumentPoolSnapshotRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Read-only operator work queue projection, never executes status transitions. */
#[AsCommand(name:'cos:capital-markets:instrument-pool:review',
    description:'Review repeated full healthy catalog snapshots; no automatic mapping or delisting.')]
final class CapitalMarketsInstrumentPoolReviewCommand extends Command
{
    public function __construct(
        private readonly MysqlInstrumentPoolSnapshotRepository $snapshots,
        private readonly InstrumentPoolLifecycleReviewer $reviewer,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('provider',InputArgument::REQUIRED,'Provider')
            ->addArgument('venue',InputArgument::REQUIRED,'Venue ID')
            ->addOption('observations',null,InputOption::VALUE_REQUIRED,'Minimum consecutive healthy snapshots','3');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $required=filter_var($input->getOption('observations'),FILTER_VALIDATE_INT);
        if(!is_int($required)||$required<2||$required>7){
            $output->writeln('INVALID_OBSERVATIONS');return Command::INVALID;
        }
        try{
            $history=$this->snapshots->history(
                (string)$input->getArgument('organization'),
                strtoupper((string)$input->getArgument('provider')),
                (string)$input->getArgument('venue'),$required,
            );
            $result=$this->reviewer->review($history,$required);
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }catch(Throwable $error){
            $output->writeln(json_encode([
                'status'=>'FAILED','error_type'=>get_class($error),
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
