<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureGate;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlBinanceStreamingExpiredLeaseSweeper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:market-data:stream:sweep-binance',
    description:'Expire abandoned Binance Spot BBO worker lease and revoke trust.')]
final class CapitalMarketsBinanceStreamingSweepCommand extends Command
{
    public function __construct(
        private readonly CapitalMarketsFeatureGate $flags,
        private readonly MarketSourceRepositoryInterface $sources,
        private readonly MysqlBinanceStreamingExpiredLeaseSweeper $sweeper,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('source',InputArgument::REQUIRED,'Binance Spot source ID');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getArgument('organization'));
        $source=trim((string)$input->getArgument('source'));
        if($org===''||$source==='')return Command::INVALID;
        if(getenv('COS_CM_STREAMING_BINANCE_SPOT_PILOT')!=='1'
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketData,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataBinance,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataStreaming,$org)){
            $output->writeln('STREAMING_DISABLED');
            return Command::FAILURE;
        }
        try{
            $descriptor=$this->sources->get($org,MarketSourceId::fromString($source));
            if($descriptor===null||!$descriptor->enabled
                ||$descriptor->adapterType!==BinanceSpotMarketDataAdapter::ADAPTER_TYPE){
                throw new \DomainException('Source is not enabled Binance Spot.');
            }
            $result=$this->sweeper->sweep($org,$source);
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }catch(Throwable $error){
            $output->writeln(json_encode(['status'=>'FAILED','reason'=>get_class($error)],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
