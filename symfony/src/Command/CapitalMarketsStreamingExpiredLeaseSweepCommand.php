<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingExpiredLeaseSweeper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Safety/repair operation. No tenant streaming flag check: an operator must
 * still be able to revoke stale streaming trust after disabling the feature.
 * Scheduling and global enablement are explicit operational decisions.
 */
#[AsCommand(
    name:'cos:capital-markets:market-data:stream:sweep-expired',
    description:'Recover expired Binance, Bybit and Kraken BBO worker leases.',
)]
final class CapitalMarketsStreamingExpiredLeaseSweepCommand extends Command
{
    public function __construct(
        private readonly MysqlStreamingExpiredLeaseSweeper $sweeper,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addOption('organization',null,InputOption::VALUE_REQUIRED,'Optional organization filter')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum candidates per invocation','100');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        if(getenv('COS_CM_STREAM_SWEEPER_ENABLED')!=='1'){
            $output->writeln('STREAM_SWEEPER_DISABLED');
            return Command::FAILURE;
        }
        $rawLimit=$input->getOption('limit');
        $limit=filter_var($rawLimit,FILTER_VALIDATE_INT);
        if($limit===false||$limit<1||$limit>500){
            $output->writeln('INVALID_LIMIT');
            return Command::INVALID;
        }
        $rawOrg=$input->getOption('organization');
        $org=is_string($rawOrg)?trim($rawOrg):null;
        if($rawOrg!==null && ($org===''||$org!==$rawOrg||strlen($org)>190)){
            $output->writeln('INVALID_ORGANIZATION');
            return Command::INVALID;
        }
        try{
            $result=$this->sweeper->sweepBatch($limit,$org);
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR));
            return $result['failed']===0?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            $output->writeln(json_encode([
                'status'=>'FAILED','error_type'=>get_class($error),
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
