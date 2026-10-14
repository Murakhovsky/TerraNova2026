<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Domain\Contract\VenueRepository;
use Domains\CapitalMarkets\Domain\Contract\InstrumentRepository;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentStatus;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifier;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifierType;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionStatus;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenuePoolCandidateRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueBboAdmissionFence;
use InvalidArgumentException;

/**
 * Fenced BBO planner for human-reviewed fresh listing + ACTIVE canonical venue mapping.
 * Listing discovery never creates canonical identity or subscribes unverified rows.
 * Planned BBO is a configured REST/market-data subscription, not a websocket uptime claim.
 */
final readonly class CrossVenueSubscriptionPlanner
{
    public function __construct(
        private CrossVenuePoolCandidateRepository $pool,
        private MarketSourceRepositoryInterface $sources,
        private MarketSubscriptionRepositoryInterface $subscriptions,
        private VenueRepository $venues,
        private InstrumentRepository $instruments,
        private MarketInstrumentResolverInterface $resolver,
        private MarketDataAdapterRegistry $adapters,
        private MarketDataAdministrationService $administration,
        private CrossVenueMarketSourceAdmission $sourceAdmission,
        private CrossVenueBboAdmissionFence $fence,
    ) {}

    /** @return array{source:string,venue:string,total:int,create:int,existing:int,blocked:int,rows:list<array<string,string>>} */
    public function plan(string $org,string $sourceId,int $limit=250): array
    {
        if ($org === '' || $sourceId === '' || $limit < 1 || $limit > 250) {
            throw new InvalidArgumentException('Invalid BBO subscription planning scope.');
        }
        $source=$this->sources->get($org,MarketSourceId::fromString($sourceId));
        if ($source===null) throw new InvalidArgumentException('Unknown market data source.');
        $venue=match($source->adapterType) {
            'binance.spot.rest'=>'BINANCE',
            'bybit.spot.v5'=>'BYBIT',
            'kraken.spot.rest'=>'KRAKEN',
            default=>throw new InvalidArgumentException('Adapter is not eligible for this pool.'),
        };
        $items=$this->pool->listedForVenue($org,$venue,500);
        if (count($items)>$limit) {
            throw new InvalidArgumentException('Verified monitoring candidates exceed requested planning capacity.');
        }
        $result=['source'=>$sourceId,'venue'=>$venue,'total'=>count($items),
            'create'=>0,'existing'=>0,'blocked'=>0,'rows'=>[]];
        $venueDescriptor=$source->venueId===null ? null : $this->venues->get($org,$source->venueId);
        if ($this->sourceAdmission->reason($org,$source)!==null || $venueDescriptor===null
            || $venueDescriptor->status!==VenueStatus::Active
            || !in_array($venueDescriptor->code,[$venue,$venue.'_SPOT'],true)) {
            foreach($items as $item) {
                $result['rows'][]=['seed_token'=>$item['seed_token'],'venue_symbol'=>$item['venue_symbol'],
                    'instrument_id'=>'','status'=>'BLOCKED_SOURCE_NOT_READY'];
                ++$result['blocked'];
            }
            return $result;
        }
        $mappings=[];
        foreach ($this->venues->instruments($org,$source->venueId) as $mapped) {
            $key=strtoupper($mapped->venueSymbol);
            $mappings[$key][]=$mapped;
        }
        $existing=[];
        foreach ($this->subscriptions->forSource($org,$source->id) as $subscription) {
            if ($subscription->dataType !== MarketEventType::Bbo) continue;
            $existing[$subscription->instrumentId->value()]=$subscription->status;
        }
        $adapter=$this->adapters->get($source->adapterType);
        foreach ($items as $item) {
            $symbol=(string)$item['venue_symbol'];
            $mapped=$mappings[strtoupper($symbol)] ?? [];
            $instrumentId='';
            $status='BLOCKED_MAPPING_REQUIRED';
            if (count($mapped)===1) {
                $venueInstrument=$mapped[0];
                $instrumentId=$venueInstrument->instrumentId->value();
                $instrument=$this->instruments->get($org,$venueInstrument->instrumentId);
                if ($instrument===null || $instrument->status!==InstrumentStatus::Active
                    || $venueInstrument->status!==VenueInstrumentStatus::Active) {
                    $status='BLOCKED_INACTIVE_CANONICAL_INSTRUMENT';
                } elseif (($instrument->metadata['origin']??null)!=='BINANCE_BSTOCKS_SEED'
                    || ($instrument->metadata['underlying_reference_symbol']??null)===''
                    || $this->instruments->findByIdentifier($org,
                        new InstrumentIdentifier(InstrumentIdentifierType::ProviderId,
                            (string)$item['seed_token'],'BINANCE_BSTOCKS_SEED'))?->id->value()!==$instrumentId) {
                    $status='BLOCKED_CANONICAL_SEED_IDENTITY';
                } elseif (isset($existing[$instrumentId])) {
                    $status=match($existing[$instrumentId]) {
                        MarketSubscriptionStatus::Active=>'ALREADY_ACTIVE',
                        MarketSubscriptionStatus::Disabled=>'PLAN_REENABLE',
                        default=>'BLOCKED_EXISTING_SUBSCRIPTION',
                    };
                } else {
                    $target=$this->resolver->target($org,$source,$instrument->id);
                    if ($target===null || strcasecmp($target->externalSymbol,$symbol)!==0
                        || !$adapter->supports(MarketDataCapability::Bbo,$target)) {
                        $status='BLOCKED_ADAPTER_MAPPING';
                    } else {
                        $status='PLAN_CREATE';
                    }
                }
            } elseif (count($mapped)>1) {
                $status='BLOCKED_AMBIGUOUS_MAPPING';
            }
            $result['rows'][]=['seed_token'=>(string)$item['seed_token'],
                'venue_symbol'=>$symbol,'instrument_id'=>$instrumentId,'status'=>$status];
            if ($status==='PLAN_CREATE' || $status==='PLAN_REENABLE') ++$result['create'];
            elseif ($status==='ALREADY_ACTIVE') ++$result['existing'];
            else ++$result['blocked'];
        }
        return $result;
    }

    /** Creates only PLAN_CREATE entries via existing audited subscription boundary. */
    public function apply(string $org,int $actor,string $sourceId,int $limit=250): array
    {
        if ($actor < 1) throw new InvalidArgumentException('Authorized operator required.');
        $this->fence->disableUnapproved($org);
        $plan=$this->plan($org,$sourceId,$limit);
        $created=0;
        foreach ($plan['rows'] as $row) {
            if (!in_array($row['status'],['PLAN_CREATE','PLAN_REENABLE'],true)) continue;
            // Recheck current reviewer decision just before activating; never
            // use a stale whole-plan snapshot to resurrect a disabled BBO.
            $allowed=$this->pool->listedForVenue($org,$plan['venue'],500);
            if (!array_any($allowed,static fn(array $candidate):bool=>
                $candidate['seed_token']===$row['seed_token']
                && $candidate['venue_symbol']===$row['venue_symbol'])) {
                throw new InvalidArgumentException('Listing changed during BBO subscription apply.');
            }
            $this->administration->createSubscription(
                $org,$actor,'cm-pool-'.substr(hash('sha256',$org.'|'.$sourceId.'|'.$row['instrument_id']),0,32),
                $sourceId,['instrument_id'=>$row['instrument_id'],'data_type'=>'BBO']
            );
            ++$created;
        }
        return ['created'=>$created,'plan'=>$plan,
            'transport_state'=>'CONFIGURED_SUBSCRIPTIONS_NOT_STREAMING_CONFIRMED'];
    }
}
