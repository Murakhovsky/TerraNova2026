<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingOperationsSnapshot;
use Domains\CapitalMarkets\Application\Service\StreamingOperationsHealthEvaluator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Read-only CLI diagnostic; operators use the authenticated server console.
 * No start/stop/force-release endpoint is exposed through this command.
 */
#[AsCommand(name:'cos:capital-markets:market-data:stream:status',
    description:'Read tenant-filtered streaming lease and market trust status as JSON.')]
final class CapitalMarketsStreamingStatusCommand extends Command
{
    public function __construct(private readonly MysqlStreamingOperationsSnapshot $snapshot,
        private readonly StreamingOperationsHealthEvaluator $alerts,)
    {parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addOption('source',null,InputOption::VALUE_REQUIRED,'Optional source ID');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=$input->getArgument('organization');
        $source=$input->getOption('source');
        if(!is_string($org)||!is_string($source)&&$source!==null){
            $output->writeln('INVALID_ARGUMENT');return Command::INVALID;
        }
        try{
            $result=$this->snapshot->inspect($org,$source);
            $result['health_evaluation']=$this->alerts->evaluate($result);
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
