<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Domain\Contract\InstrumentRepository;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifier;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifierType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionStatus;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\BStocksSeedImportRepository;
use InvalidArgumentException;

/**
 * Subscribes independent parent equity/ETF quotes through configured Massive
 * reference source. Does NOT establish token backing, conversion or parity.
 */
final readonly class BStocksReferenceBboPlanner
{
    public function __construct(
        private BStocksSeedImportRepository $seeds,
        private BStocksCanonicalDraftProjection $projection,
        private InstrumentRepository $instruments,
        private MarketSourceRepositoryInterface $sources,
        private MarketSubscriptionRepositoryInterface $subscriptions,
        private MarketInstrumentResolverInterface $resolver,
        private MarketDataAdapterRegistry $adapters,
        private MarketDataAdministrationService $administration,
        private CrossVenueMarketSourceAdmission $admission,
    ) {}

    /** @return array<string,mixed> */
    public function plan(string $organizationId,string $sourceId,int $limit=250):array
    {
        if ($organizationId==='' || $sourceId==='' || $limit<1 || $limit>250) {
            throw new InvalidArgumentException('Invalid reference subscription scope.');
        }
        $source=$this->sources->get($organizationId,MarketSourceId::fromString($sourceId));
        if ($source===null || $source->adapterType!=='massive.stocks.v2' || $source->venueId!==null) {
            throw new InvalidArgumentException('Reference requires a configured Massive source without venue ID.');
        }
        $seedRows=$this->seeds->listForCanonicalDraft($organizationId);
        $unique=[];
        foreach ($seedRows as $evidence) {
            $input=$this->projection->createUnderlyingInput($organizationId,$evidence);
            $symbol=$input['symbol'];
            if (isset($unique[$symbol])) {
                if ($unique[$symbol]['family']!==$input['family']) {
                    throw new InvalidArgumentException('Conflicting equity/ETF family for underlying.');
                }
                continue;
            }
            $unique[$symbol]=$input;
        }
        if (count($unique)>$limit) throw new InvalidArgumentException('Reference candidate count exceeds planning cap.');
        ksort($unique,SORT_STRING);
        $out=['source'=>$sourceId,'kind'=>'UNDERLYING_REFERENCE_BBO','total'=>count($unique),
            'create'=>0,'existing'=>0,'blocked'=>0,'rows'=>[]];
        $blocked=$this->admission->reason($organizationId,$source,false,true);
        if (!in_array($source->metadata['data_mode']??null,['LIVE','DELAYED'],true)
            || $source->credentialsReference===null) {
            $blocked='MASSIVE_REFERENCE_CONFIG_INCOMPLETE';
        }
        $existing=[];
        foreach ($this->subscriptions->forSource($organizationId,$source->id) as $sub) {
            if ($sub->dataType!==MarketEventType::Bbo) continue;
            $existing[$sub->instrumentId->value()]=$sub->status;
        }
        $adapter=$this->adapters->get($source->adapterType);
        foreach ($unique as $symbol=>$input) {
            $id=InstrumentId::fromString($input['id']);
            $instrument=$this->instruments->get($organizationId,$id);
            $status='BLOCKED_CANONICAL_REFERENCE_REQUIRED';
            if ($blocked!==null) {
                $status='BLOCKED_'.$blocked;
            } elseif ($instrument===null
                || $instrument->status!==InstrumentStatus::Active
                || $instrument->family->value!==$input['family']
                || ($instrument->metadata['origin']??null)!=='BINANCE_BSTOCKS_UNDERLYING_SEED'
                || ($instrument->metadata['underlying_reference_symbol']??null)!==$symbol
                || $this->instruments->findByIdentifier($organizationId,
                    new InstrumentIdentifier(InstrumentIdentifierType::ProviderId,
                        $symbol,'BINANCE_BSTOCKS_UNDERLYING_SEED'))?->id->value()!==$id->value()) {
                $status='BLOCKED_CANONICAL_REFERENCE_REQUIRED';
            } elseif (isset($existing[$id->value()])) {
                $status=match($existing[$id->value()]) {
                    MarketSubscriptionStatus::Active=>'ALREADY_ACTIVE',
                    MarketSubscriptionStatus::Disabled=>'PLAN_REENABLE',
                    default=>'BLOCKED_EXISTING_SUBSCRIPTION',
                };
            } else {
                $target=$this->resolver->target($organizationId,$source,$id);
                if ($target===null || $target->venueInstrument!==null
                    || strcasecmp($target->externalSymbol,$symbol)!==0
                    || !$adapter->supports(MarketDataCapability::Bbo,$target)) {
                    $status='BLOCKED_REFERENCE_RESOLUTION';
                } else {
                    $status='PLAN_CREATE';
                }
            }
            $out['rows'][]=['symbol'=>$symbol,'instrument_id'=>$id->value(),'status'=>$status];
            if ($status==='PLAN_CREATE' || $status==='PLAN_REENABLE') ++$out['create'];
            elseif ($status==='ALREADY_ACTIVE') ++$out['existing'];
            else ++$out['blocked'];
        }
        return $out;
    }

    /** Uses the existing audited MarketDataAdministrationService subscription boundary. */
    public function apply(string $organizationId,int $actorId,string $sourceId,int $limit=250):array
    {
        if ($actorId<1) throw new InvalidArgumentException('Positive authorized actor required.');
        $plan=$this->plan($organizationId,$sourceId,$limit);
        $created=0;
        foreach ($plan['rows'] as $row) {
            if (!in_array($row['status'],['PLAN_CREATE','PLAN_REENABLE'],true)) continue;
            $fresh=$this->plan($organizationId,$sourceId,$limit);
            $approved=false;
            foreach ($fresh['rows'] as $current) {
                if ($current['instrument_id']===$row['instrument_id']
                    && in_array($current['status'],['PLAN_CREATE','PLAN_REENABLE'],true)) {
                    $approved=true;break;
                }
            }
            if (!$approved) throw new InvalidArgumentException('Reference BBO eligibility changed during apply.');
            $this->administration->createSubscription(
                $organizationId,$actorId,
                'cm-underlying-bbo-'.substr(hash('sha256',$organizationId.'|'.$sourceId.'|'.$row['instrument_id']),0,32),
                $sourceId,['instrument_id'=>$row['instrument_id'],'data_type'=>'BBO'],
            );
            ++$created;
        }
        return ['created'=>$created,'plan'=>$plan,
            'transport_state'=>'CONFIGURED_SUBSCRIPTIONS_NOT_REFERENCE_FEED_CONFIRMED',
            'economic_link_verified'=>false];
    }
}
