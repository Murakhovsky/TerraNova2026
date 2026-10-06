<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Okx;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\DerivativeMarketDataAdapterInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Instrument\ContractType;
use Domains\CapitalMarkets\Domain\Instrument\MarginMode;
use Domains\CapitalMarkets\Domain\Instrument\PerpetualProfile;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateType;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;
use Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver;
use InvalidArgumentException;

final readonly class OkxPerpetualMarketDataAdapter implements DerivativeMarketDataAdapterInterface
{
    public const ADAPTER_TYPE='okx.swap.v5';
    private const HOST='www.okx.com';
    private const BASE_URL='https://www.okx.com';

    public function __construct(
        private MarketJsonHttpClientInterface $http,
        private MarketClockInterface $clock,
        private OkxPublicPayloadParser $parser,
        private VenueMarketStatusResolver $status,
    ){}

    public function adapterType():string{return self::ADAPTER_TYPE;}
    public function getSource():string{return 'OKX';}
    public function getCapabilities():array{return [
        MarketDataCapability::Bbo,MarketDataCapability::Volume,MarketDataCapability::OrderBook,
        MarketDataCapability::Funding,MarketDataCapability::OpenInterest,MarketDataCapability::MarkPrice,
        MarketDataCapability::IndexPrice,MarketDataCapability::DerivativesMetadata,
    ];}

    public function supports(MarketDataCapability $capability,MarketDataInstrumentTarget $target):bool
    {
        return in_array($capability,$this->getCapabilities(),true)&&$target->venueInstrument!==null;
    }

    public function resolveInstrument(string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target):?string
    {
        if($source->adapterType!==self::ADAPTER_TYPE||$source->venueId===null||$target->venueInstrument===null)return null;
        if(!$target->venueInstrument->venueId->equals($source->venueId))return null;
        $id=strtoupper($target->externalSymbol);
        return preg_match('/^[A-Z0-9][A-Z0-9._:-]{1,119}$/',$id)===1?$id:null;
    }

    public function getSnapshot(string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,array $capabilities):MarketDataBatch
    {
        $instId=$this->resolveInstrument($organizationId,$source,$target);
        if($instId===null)throw new InvalidArgumentException('OKX perpetual target is unresolved.');
        if($capabilities===[])throw new InvalidArgumentException('OKX perpetual snapshot requires capabilities.');
        $requested=[];
        foreach($capabilities as $cap){if(!$cap instanceof MarketDataCapability||!$this->supports($cap,$target))throw new InvalidArgumentException('Unsupported OKX perpetual capability.');$requested[$cap->value]=true;}
        $received=$this->clock->now();
        $marketStatus=$this->status->resolve($target->venueInstrument,$received)->value;
        $instrumentBody=$this->get($organizationId,'instrument','/api/v5/public/instruments?instType=SWAP&instId='.rawurlencode($instId));
        $instrument=$this->parser->instrument($instrumentBody,$instId);
        if(strtolower((string)($instrument['ctType']??''))!=='linear')throw new InvalidArgumentException('VS2 V1 supports only OKX linear swaps.');
        $indexId=(string)($instrument['uly']??'');
        if($indexId==='')throw new InvalidArgumentException('OKX swap underlying index is missing.');
        $events=[];

        if(isset($requested[MarketDataCapability::Bbo->value])||isset($requested[MarketDataCapability::Volume->value])){
            $body=$this->get($organizationId,'ticker','/api/v5/market/ticker?instId='.rawurlencode($instId));
            $row=$this->parser->ticker($body,$instId);$at=ProviderTimestamp::fromMilliseconds($this->parser->unsigned($row['ts']??null,'ticker timestamp'));
            foreach([[MarketDataCapability::Bbo,'okx.swap.ticker.bbo'],[MarketDataCapability::Volume,'okx.swap.ticker.volume']] as [$cap,$type]){
                if(!isset($requested[$cap->value]))continue;
                $events[]=$this->raw($source,$instId,$type,$at,$received,['ticker_json'=>$body,'instrument_json'=>$instrumentBody],$marketStatus,'/api/v5/market/ticker');
            }
        }
        if(isset($requested[MarketDataCapability::Funding->value])){
            $body=$this->get($organizationId,'funding','/api/v5/public/funding-rate?instId='.rawurlencode($instId));
            $row=$this->parser->funding($body,$instId);$at=ProviderTimestamp::fromMilliseconds($this->parser->unsigned($row['ts']??$row['fundingTime']??null,'funding timestamp'));
            $events[]=$this->raw($source,$instId,'okx.swap.funding',$at,$received,['funding_json'=>$body,'instrument_json'=>$instrumentBody],$marketStatus,'/api/v5/public/funding-rate');
        }
        if(isset($requested[MarketDataCapability::MarkPrice->value])){
            $body=$this->get($organizationId,'mark','/api/v5/public/mark-price?instType=SWAP&instId='.rawurlencode($instId));
            $row=$this->parser->mark($body,$instId);$at=ProviderTimestamp::fromMilliseconds($this->parser->unsigned($row['ts']??null,'mark timestamp'));
            $events[]=$this->raw($source,$instId,'okx.swap.mark_price',$at,$received,['mark_json'=>$body],$marketStatus,'/api/v5/public/mark-price');
        }
        if(isset($requested[MarketDataCapability::IndexPrice->value])){
            $body=$this->get($organizationId,'index','/api/v5/market/index-tickers?instId='.rawurlencode($indexId));
            $row=$this->parser->index($body,$indexId);$at=ProviderTimestamp::fromMilliseconds($this->parser->unsigned($row['ts']??null,'index timestamp'));
            $events[]=$this->raw($source,$instId,'okx.swap.index_price',$at,$received,['index_json'=>$body,'index_id'=>$indexId],$marketStatus,'/api/v5/market/index-tickers');
        }
        if(isset($requested[MarketDataCapability::OpenInterest->value])){
            $body=$this->get($organizationId,'oi','/api/v5/public/open-interest?instType=SWAP&instId='.rawurlencode($instId));
            $row=$this->parser->openInterest($body,$instId);$at=ProviderTimestamp::fromMilliseconds($this->parser->unsigned($row['ts']??null,'open interest timestamp'));
            $events[]=$this->raw($source,$instId,'okx.swap.open_interest',$at,$received,['oi_json'=>$body,'instrument_json'=>$instrumentBody],$marketStatus,'/api/v5/public/open-interest');
        }
        if(isset($requested[MarketDataCapability::OrderBook->value])){
            $body=$this->get($organizationId,'book','/api/v5/market/books?instId='.rawurlencode($instId).'&sz=50');
            $book=$this->parser->book($body);$at=ProviderTimestamp::fromMilliseconds($book['timestamp']);
            $events[]=$this->raw($source,$instId,'okx.swap.orderbook.snapshot',$at,$received,['book_json'=>$body,'instrument_json'=>$instrumentBody],$marketStatus,'/api/v5/market/books',$book['sequence']);
        }
        if(isset($requested[MarketDataCapability::DerivativesMetadata->value])){
            $events[]=$this->raw($source,$instId,'okx.swap.instrument.metadata',$received,$received,['instrument_json'=>$instrumentBody],$marketStatus,'/api/v5/public/instruments');
        }
        return new MarketDataBatch($source->id,$events);
    }

    public function getPerpetualProfile(string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target):PerpetualProfile
    {
        $instId=$this->resolveInstrument($organizationId,$source,$target);
        if($instId===null)throw new InvalidArgumentException('OKX perpetual target is unresolved.');
        $body=$this->get($organizationId,'instrument','/api/v5/public/instruments?instType=SWAP&instId='.rawurlencode($instId));
        $row=$this->parser->instrument($body,$instId);
        $ctType=strtolower((string)($row['ctType']??''))==='linear'?ContractType::Linear:(strtolower((string)($row['ctType']??''))==='inverse'?ContractType::Inverse:ContractType::Other);
        $ctVal=Decimal::fromString($this->parser->decimal($row['ctVal']??null,'ctVal',true));
        $ctMultRaw=(string)($row['ctMult']??'');$ctMult=Decimal::fromString($ctMultRaw===''?'1':$this->parser->decimal($ctMultRaw,'ctMult',true));
        $minContracts=Decimal::fromString($this->parser->decimal($row['minSz']??null,'minSz',true));
        $minUnderlying=DecimalMath::multiply(DecimalMath::multiply($minContracts,$ctVal),$ctMult);
        $tick=$this->parser->decimal($row['tickSz']??null,'tickSz',true);
        $lot=$this->parser->decimal($row['lotSz']??null,'lotSz',true);
        $settle=(string)($row['settleCcy']??'');$base=(string)($row['ctValCcy']??'');
        if($settle===''||$base==='')throw new InvalidArgumentException('OKX settlement/contract-value currency is missing.');
        $fundingBody=$this->get($organizationId,'funding','/api/v5/public/funding-rate?instId='.rawurlencode($instId));
        $fund=$this->parser->funding($fundingBody,$instId);
        $from=(int)$this->parser->unsigned($fund['fundingTime']??null,'fundingTime');
        $to=(int)$this->parser->unsigned($fund['nextFundingTime']??null,'nextFundingTime');
        $interval=intdiv(max(0,$to-$from),1000);
        if($interval<60)throw new InvalidArgumentException('OKX funding interval cannot be derived.');
        $maxLevRaw=(string)($row['lever']??'');$maxLev=$maxLevRaw===''?'1':$this->parser->decimal($maxLevRaw,'lever',true);
        return new PerpetualProfile(
            new AssetCode($base),$ctType,new AssetCode($settle),new AssetCode($settle),$ctVal,$ctMult,
            $this->parser->precision($tick),$this->parser->precision($lot),$minUnderlying,Decimal::fromString('0'),
            Decimal::fromString($maxLev),[MarginMode::Isolated,MarginMode::Cross],true,$interval,
            'OKX_MARK_PRICE','OKX_INDEX_PRICE','OKX_V5_SWAP',
            ['inst_id'=>$instId,'underlying_index'=>(string)($row['uly']??''),'state'=>(string)($row['state']??''),'ct_val_ccy'=>$base],
        );
    }

    public function getFundingHistory(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,
        DateTimeImmutable $from,DateTimeImmutable $to,int $limit=200,
    ):array{
        if($limit<1||$limit>400||$to<$from)throw new InvalidArgumentException('Invalid OKX funding history range.');
        $instId=$this->resolveInstrument($organizationId,$source,$target);
        if($instId===null||$source->venueId===null)throw new InvalidArgumentException('OKX perpetual target is unresolved.');
        $profile=$this->getPerpetualProfile($organizationId,$source,$target);
        $url='/api/v5/public/funding-rate-history?instId='.rawurlencode($instId)
            .'&before='.($from->getTimestamp()*1000).'&after='.($to->getTimestamp()*1000).'&limit='.$limit;
        $rows=$this->parser->fundingHistory($this->get($organizationId,'funding_history',$url),$instId);
        $out=[];
        foreach($rows as $row){
            $ts=(int)$this->parser->unsigned($row['fundingTime']??null,'fundingTime');
            $at=ProviderTimestamp::fromMilliseconds((string)$ts);
            if($at<$from||$at>$to)continue;
            $rate=(string)($row['realizedRate']??'');
            if($rate===''||preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/',$rate)!==1)$rate=$this->parser->decimal($row['fundingRate']??null,'fundingRate');
            $out[]=new FundingRateObservation(
                $source->venueId,$target->instrument->id,Decimal::fromString($rate),FundingRateType::NormalizedLongsPayShorts,
                $at,null,$profile->fundingIntervalSeconds??throw new InvalidArgumentException('OKX funding interval unavailable.'),
                null,null,'OKX_SETTLED_FUNDING',100,FundingRateStatus::Settled,
            );
        }
        return $out;
    }

    public function getHealth(string $organizationId,MarketSourceDescriptor $source):MarketSourceHealth
    {
        return new MarketSourceHealth($source->id,$source->enabled?MarketConnectionState::Connected:MarketConnectionState::Disabled,null,null,0,0,$this->clock->reliable());
    }

    private function get(string $organizationId,string $purpose,string $path):string
    {
        return $this->http->get($organizationId,'capital_markets.okx.swap.'.$purpose,self::BASE_URL.$path,[],[self::HOST]);
    }

    /** @param array<string,mixed> $payload */
    private function raw(
        MarketSourceDescriptor $source,string $instId,string $type,DateTimeImmutable $providerAt,DateTimeImmutable $received,
        array $payload,string $marketStatus,string $endpoint,?string $sequence=null,
    ):RawMarketEvent{
        return new RawMarketEvent(
            'cm-raw-okx-swap-'.bin2hex(random_bytes(12)),$source->id,$source->venueId,$instId,$type,$providerAt,$received,$sequence,$payload,
            ['provider'=>'OKX','transport'=>'REST','endpoint'=>$endpoint,'market_status'=>$marketStatus],
        );
    }
}
