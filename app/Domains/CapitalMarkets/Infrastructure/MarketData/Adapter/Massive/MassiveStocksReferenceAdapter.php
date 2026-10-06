<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Massive;

use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketDataAdapterInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Infrastructure\MarketData\Security\MarketSourceCredentialResolver;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;
use InvalidArgumentException;

final readonly class MassiveStocksReferenceAdapter implements MarketDataAdapterInterface
{
    public const ADAPTER_TYPE='massive.stocks.v2';
    private const HOST='api.massive.com';
    private const BASE_URL='https://api.massive.com';

    public function __construct(
        private MarketJsonHttpClientInterface $http,
        private MarketSourceCredentialResolver $credentials,
        private MarketClockInterface $clock,
        private MassiveQuotePayloadParser $parser,
    ){}

    public function adapterType():string{return self::ADAPTER_TYPE;}
    public function getSource():string{return 'MASSIVE';}

    public function getCapabilities():array
    {
        return [MarketDataCapability::Bbo];
    }

    public function supports(MarketDataCapability $capability,MarketDataInstrumentTarget $target):bool
    {
        return in_array($capability,$this->getCapabilities(),true)&&$target->venueInstrument===null;
    }

    public function resolveInstrument(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
    ):?string{
        if($source->adapterType!==self::ADAPTER_TYPE||$source->venueId!==null)return null;
        $symbol=strtoupper($target->externalSymbol);
        if(preg_match('/^[A-Z][A-Z0-9.-]{0,31}$/',$symbol)!==1)return null;
        return $symbol;
    }

    public function getSnapshot(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
        array $capabilities,
    ):MarketDataBatch{
        $symbol=$this->resolveInstrument($organizationId,$source,$target);
        if($symbol===null)throw new InvalidArgumentException('Massive reference target is invalid.');
        if($capabilities===[])throw new InvalidArgumentException('Massive snapshot requires BBO capability.');
        foreach($capabilities as $capability){
            if(!$capability instanceof MarketDataCapability||!$this->supports($capability,$target)){
                throw new InvalidArgumentException('Unsupported Massive snapshot capability.');
            }
        }
        $mode=$this->dataMode($source);
        $apiKey=$this->credentials->apiKey($organizationId,$source,'capital_markets.market_data.read');

        $url=self::BASE_URL.'/v2/last/nbbo/'.rawurlencode($symbol);
        $body=$this->http->get(
            $organizationId,
            'capital_markets.massive.stocks.nbbo',
            $url,
            ['Authorization: Bearer '.$apiKey],
            [self::HOST],
        );
        $quote=$this->parser->parse($body,$symbol);
        $receivedAt=$this->clock->now();
        $providerAt=ProviderTimestamp::fromNanoseconds($quote['sipTimestampNs']);

        return new MarketDataBatch($source->id,[
            new RawMarketEvent(
                $this->eventId(),$source->id,null,$symbol,'massive.stocks.nbbo',
                $providerAt,$receivedAt,$quote['sequence'],['raw_json'=>$body],
                ['provider'=>'MASSIVE','transport'=>'REST','endpoint'=>'/v2/last/nbbo/{ticker}'],
                $mode,
            ),
        ]);
    }

    public function getHealth(string $organizationId,MarketSourceDescriptor $source):MarketSourceHealth
    {
        return new MarketSourceHealth(
            $source->id,
            $source->enabled?MarketConnectionState::Connected:MarketConnectionState::Disabled,
            null,
            null,
            0,
            0,
            $this->clock->reliable(),
        );
    }

    private function dataMode(MarketSourceDescriptor $source):MarketDataMode
    {
        $value=$source->metadata['data_mode']??null;
        if(!is_string($value)||trim($value)===''){
            throw new InvalidArgumentException('Massive source metadata.data_mode must explicitly declare LIVE or DELAYED.');
        }
        $mode=MarketDataMode::from(strtoupper(trim($value)));
        if(!in_array($mode,[MarketDataMode::Live,MarketDataMode::Delayed],true)){
            throw new InvalidArgumentException('Massive REST reference source supports LIVE or DELAYED mode only.');
        }
        return $mode;
    }

    private function eventId():string
    {
        return 'cm-raw-massive-nbbo-'.bin2hex(random_bytes(12));
    }
}
