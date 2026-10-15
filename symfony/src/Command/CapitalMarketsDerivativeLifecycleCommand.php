<?php
declare(strict_types=1);
namespace App\Command;
use DomainException;
use Domains\CapitalMarkets\Application\Feature\{CapitalMarketsFeatureFlag,CapitalMarketsFeatureGate};
use Domains\CapitalMarkets\Application\Service\BybitDerivativesStreamScope;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitPerpetualMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\{
    MysqlDerivativeLifecycleRegistry,MysqlDerivativeStreamTrust
};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputOption,InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:market-data:derivatives:lifecycle',
    description:'Poll provider-authoritative Bybit contract lifecycle or read trust; off by default.')]
final class CapitalMarketsDerivativeLifecycleCommand extends Command
{
    public function __construct(
        private readonly CapitalMarketsFeatureGate $flags,
        private readonly BybitDerivativesStreamScope $scope,
        private readonly BybitPerpetualMarketDataAdapter $provider,
        private readonly MysqlDerivativeLifecycleRegistry $lifecycle,
        private readonly MysqlDerivativeStreamTrust $trust,
    ){parent::__construct();}
    protected function configure():void{
        $this->addArgument('operation',InputArgument::REQUIRED,'poll|status|sweep')
            ->addArgument('organization',InputArgument::REQUIRED)
            ->addArgument('source',InputArgument::REQUIRED)
            ->addOption('stale-seconds',null,InputOption::VALUE_REQUIRED,'Freshness threshold 30..3600','120');
    }
    protected function execute(InputInterface $in,OutputInterface $out):int{
        try{
            $op=(string)$in->getArgument('operation');
            $org=trim((string)$in->getArgument('organization'));
            $source=trim((string)$in->getArgument('source'));
            if($org===''||$source===''||strlen($org)>190||strlen($source)>190
                ||!in_array($op,['poll','status','sweep'],true)){
                throw new DomainException('Invalid derivatives operator scope.');
            }
            if($op==='status'){
                $out->writeln(json_encode(['status'=>'READ_ONLY',
                    'capabilities'=>$this->trust->status($org,$source)],JSON_THROW_ON_ERROR));
                return Command::SUCCESS;
            }
            if($op==='sweep'){
                if(getenv('COS_CM_DERIVATIVES_LIFECYCLE_ENABLED')!=='1')
                    throw new DomainException('Derivative stale sweep disabled.');
                $age=filter_var($in->getOption('stale-seconds'),FILTER_VALIDATE_INT);
                if($age===false)throw new DomainException('Invalid age.');
                $expired=$this->trust->sweep($org,$source,$age);
                $out->writeln(json_encode(['status'=>'SWEPT','expired'=>$expired],JSON_THROW_ON_ERROR));
                return Command::SUCCESS;
            }
            if(getenv('COS_CM_DERIVATIVES_LIFECYCLE_ENABLED')!=='1'
                ||!$this->flags->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$org)
                ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketData,$org)
                ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataBybit,$org)
                ||!$this->flags->enabled(CapitalMarketsFeatureFlag::CryptoSpotPerpetual,$org)){
                throw new DomainException('Derivatives lifecycle polling explicitly disabled.');
            }
            $scope=$this->scope->resolve($org,$source);
            $profile=$this->provider->getPerpetualProfile(
                $org,$scope['source'],$scope['target'],
            );
            $metadata=$profile->venueMetadata;
            $metadata['contract_type']=$profile->contractType->value==='LINEAR'
                ?'LinearPerpetual':'UNSUPPORTED';
            $result=$this->lifecycle->observe(
                $org,$source,$scope['instrument_id'],$metadata,
            );
            $out->writeln(json_encode(['status'=>'OBSERVED','source'=>$source,
                'instrument_id'=>$scope['instrument_id'],'contract'=>$result],
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }catch(Throwable $e){
            $out->writeln(json_encode(['status'=>'REJECTED','reason'=>get_class($e)],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
