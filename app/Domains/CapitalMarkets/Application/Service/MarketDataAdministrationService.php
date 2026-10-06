<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditAction;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditResourceType;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditTrail;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionStatus;
use Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy;
use Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class MarketDataAdministrationService
{
    public function __construct(
        private MarketSourceRepositoryInterface $sources,
        private MarketSubscriptionRepositoryInterface $subscriptions,
        private MarketStateRepositoryInterface $states,
        private MarketInstrumentResolverInterface $instruments,
        private MarketDataAdapterRegistry $adapters,
        private MarketSourcePollingService $polling,
        private CapitalMarketsAuditTrail $audit,
        private TransactionManagerInterface $transactions,
        private MarketClockInterface $clock,
    ){}

    /** @return array<string,mixed> */
    public function dashboard(string $organizationId):array
    {
        $sources=[];
        foreach($this->sources->list($organizationId) as $source){
            $sources[]=$this->sourceArray(
                $organizationId,
                $source,
                $this->subscriptions->forSource($organizationId,$source->id),
            );
        }

        return [
            'sources'=>$sources,
            'states'=>array_map(static fn($state):array=>$state->toArray(),$this->states->list($organizationId,250)),
            'reference_states'=>array_map(static fn($state):array=>$state->toArray(),$this->states->listReferences($organizationId,250)),
            'adapter_types'=>$this->adapters->types(),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createSource(
        string $organizationId,
        int $actorId,
        string $correlationId,
        array $input,
    ):array{
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$input):array{
            $id=MarketSourceId::fromString($this->requiredString($input,'source_id',190));
            if($this->sources->get($organizationId,$id)!==null)throw new DomainException('Market-data source already exists.');

            $adapterType=$this->requiredString($input,'adapter_type',120);
            $this->adapters->get($adapterType);

            $venueRaw=$this->optionalString($input['venue_id']??null,190);
            $roles=$this->roles($input['roles']??null);
            $metadata=[];
            $dataMode=$this->optionalString($input['data_mode']??null,24);
            if($dataMode!==null)$metadata['data_mode']=strtoupper($dataMode);

            $source=new MarketSourceDescriptor(
                $id,
                $venueRaw===null?null:VenueId::fromString($venueRaw),
                $adapterType,
                false,
                $this->integer($input['priority']??100,0,10000,'priority'),
                $roles,
                $this->optionalString($input['credentials_reference']??null,190),
                new RateLimitPolicy(
                    $this->integer($input['request_budget']??60,1,1000000,'request_budget'),
                    $this->integer($input['window_seconds']??60,1,86400,'window_seconds'),
                ),
                new ReconnectPolicy(),
                new MarketHealthPolicy(),
                $metadata,
                $this->optionalString($input['license_profile']??null,120)??'UNSPECIFIED',
                null,
            );

            $this->sources->save($organizationId,$source);
            $next=$this->sourceArray($organizationId,$source,[]);
            $this->audit->record(
                $organizationId,$actorId,CapitalMarketsAuditAction::MarketDataSourceCreated,
                CapitalMarketsAuditResourceType::MarketDataSource,$id->value(),[],$next,$correlationId
            );
            return $next;
        });
    }

    /** @return array<string,mixed> */
    public function setSourceEnabled(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $sourceId,
        bool $enabled,
    ):array{
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$sourceId,$enabled):array{
            $id=MarketSourceId::fromString($sourceId);
            $current=$this->sources->get($organizationId,$id);
            if($current===null)throw new DomainException('Market-data source was not found.');
            $previous=$this->sourceArray($organizationId,$current,$this->subscriptions->forSource($organizationId,$id));

            $next=new MarketSourceDescriptor(
                $current->id,$current->venueId,$current->adapterType,$enabled,$current->priority,$current->roles,
                $current->credentialsReference,$current->rateLimitPolicy,$current->reconnectPolicy,$current->healthPolicy,
                $current->metadata,$current->licenseProfile,$current->qualityPolicy,
            );
            $this->sources->save($organizationId,$next);
            $nextArray=$this->sourceArray($organizationId,$next,$this->subscriptions->forSource($organizationId,$id));
            $this->audit->record(
                $organizationId,$actorId,
                $enabled?CapitalMarketsAuditAction::MarketDataSourceEnabled:CapitalMarketsAuditAction::MarketDataSourceDisabled,
                CapitalMarketsAuditResourceType::MarketDataSource,$id->value(),$previous,$nextArray,$correlationId
            );
            return $nextArray;
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createSubscription(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $sourceId,
        array $input,
    ):array{
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$sourceId,$input):array{
            $sourceKey=MarketSourceId::fromString($sourceId);
            $source=$this->sources->get($organizationId,$sourceKey);
            if($source===null)throw new DomainException('Market-data source was not found.');

            $instrumentId=\Domains\CapitalMarkets\Domain\Instrument\InstrumentId::fromString(
                $this->requiredString($input,'instrument_id',190)
            );
            $eventType=MarketEventType::from(strtoupper($this->requiredString($input,'data_type',48)));
            $target=$this->instruments->target($organizationId,$source,$instrumentId);
            if($target===null)throw new DomainException('Instrument is not mapped for this market-data source.');
            $capability=$this->capability($eventType);
            $adapter=$this->adapters->get($source->adapterType);
            if(!$adapter->supports($capability,$target)){
                throw new DomainException('Provider adapter does not support this subscription type for the selected instrument.');
            }

            $subscriptionId=MarketSubscriptionId::fromString(
                'sub:'.substr(hash('sha256',implode('|',[
                    $organizationId,$sourceKey->value(),$source->venueId?->value()??'',
                    $instrumentId->value(),$eventType->value
                ])),0,40)
            );
            $subscription=new MarketSubscription(
                $subscriptionId,$sourceKey,$source->venueId,$instrumentId,$eventType,
                MarketSubscriptionStatus::Active,$this->clock->now()
            );
            $this->subscriptions->save($organizationId,$subscription);
            $next=$this->subscriptionArray($subscription);
            $this->audit->record(
                $organizationId,$actorId,CapitalMarketsAuditAction::MarketDataSubscriptionSaved,
                CapitalMarketsAuditResourceType::MarketDataSubscription,$subscriptionId->value(),[],$next,$correlationId
            );
            return $next;
        });
    }

    /** @return array<string,mixed> */
    public function poll(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $sourceId,
        int $limit=100,
    ):array{
        $id=MarketSourceId::fromString($sourceId);
        if($this->sources->get($organizationId,$id)===null)throw new DomainException('Market-data source was not found.');
        $result=$this->polling->poll($organizationId,$id,$limit)->toArray();
        $this->audit->record(
            $organizationId,$actorId,CapitalMarketsAuditAction::MarketDataPollTriggered,
            CapitalMarketsAuditResourceType::MarketDataSource,$id->value(),[],['poll_result'=>$result],$correlationId
        );
        return $result;
    }

    /** @param list<MarketSubscription> $subscriptions @return array<string,mixed> */
    private function sourceArray(string $organizationId,MarketSourceDescriptor $source,array $subscriptions):array
    {
        $health=$this->sources->health($organizationId,$source->id);
        try{$adapter=$this->adapters->get($source->adapterType);$adapterRegistered=true;}
        catch(RuntimeException){$adapter=null;$adapterRegistered=false;}

        return [
            'id'=>$source->id->value(),
            'venue_id'=>$source->venueId?->value(),
            'adapter_type'=>$source->adapterType,
            'adapter_registered'=>$adapterRegistered,
            'enabled'=>$source->enabled,
            'priority'=>$source->priority,
            'roles'=>array_map(static fn(MarketSourceRole $role):string=>$role->value,$source->roles),
            'credentials_configured'=>$source->credentialsReference!==null,
            'license_profile'=>$source->licenseProfile,
            'metadata'=>$source->metadata,
            'capabilities'=>$adapter===null?[]:array_map(static fn(MarketDataCapability $capability):string=>$capability->value,$adapter->getCapabilities()),
            'health'=>$health===null?null:[
                'connection_state'=>$health->connectionState->value,
                'last_event_at'=>$health->lastEventAt?->format(DATE_ATOM),
                'last_heartbeat_at'=>$health->lastHeartbeatAt?->format(DATE_ATOM),
                'failure_count'=>$health->failureCount,
                'queue_lag'=>$health->queueLag,
                'clock_reliable'=>$health->clockReliable,
                'last_error'=>$health->lastError,
                'messages_per_second'=>$health->messagesPerSecond?->value(),
                'last_latency_ms'=>$health->lastLatencyMilliseconds,
                'error_count'=>$health->errorCount,
                'reconnect_count'=>$health->reconnectCount,
                'rate_limit_state'=>$health->rateLimitState->value,
            ],
            'subscriptions'=>array_map(fn(MarketSubscription $subscription):array=>$this->subscriptionArray($subscription),$subscriptions),
        ];
    }

    /** @return array<string,mixed> */
    private function subscriptionArray(MarketSubscription $subscription):array
    {
        return [
            'id'=>$subscription->id->value(),
            'source_id'=>$subscription->sourceId->value(),
            'venue_id'=>$subscription->venueId?->value(),
            'instrument_id'=>$subscription->instrumentId->value(),
            'data_type'=>$subscription->dataType->value,
            'status'=>$subscription->status->value,
            'subscribed_at'=>$subscription->subscribedAt->format(DATE_ATOM),
            'last_event_at'=>$subscription->lastEventAt?->format(DATE_ATOM),
        ];
    }

    private function capability(MarketEventType $type):MarketDataCapability
    {
        return match($type){
            MarketEventType::Quote,MarketEventType::Bbo=>MarketDataCapability::Bbo,
            MarketEventType::Trade=>MarketDataCapability::Trades,
            MarketEventType::OrderBookSnapshot,MarketEventType::OrderBookDelta=>MarketDataCapability::OrderBook,
            MarketEventType::Candle=>MarketDataCapability::Candles,
            MarketEventType::Volume=>MarketDataCapability::Volume,
            MarketEventType::FundingRate=>MarketDataCapability::Funding,
            MarketEventType::OpenInterest=>MarketDataCapability::OpenInterest,
            MarketEventType::ReferencePrice,MarketEventType::MarkPrice,MarketEventType::IndexPrice=>MarketDataCapability::ReferencePrice,
        };
    }

    /** @param mixed $value @return list<MarketSourceRole> */
    private function roles(mixed $value):array
    {
        $items=is_array($value)?$value:(is_string($value)?preg_split('/\s*,\s*/',trim($value)):$value);
        if(!is_array($items)||$items===[])throw new InvalidArgumentException('roles must contain at least one role.');
        $roles=[];
        foreach($items as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException('roles contains an invalid role.');
            $role=MarketSourceRole::from(strtoupper(trim($item)));
            $roles[$role->value]=$role;
        }
        return array_values($roles);
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input,string $key,int $max):string
    {
        $value=$input[$key]??null;
        if(!is_string($value)||trim($value)===''||mb_strlen(trim($value))>$max){
            throw new InvalidArgumentException($key.' is required.');
        }
        return trim($value);
    }

    private function optionalString(mixed $value,int $max):?string
    {
        if($value===null||$value==='')return null;
        if(!is_string($value)||trim($value)===''||mb_strlen(trim($value))>$max){
            throw new InvalidArgumentException('Optional string value is invalid.');
        }
        return trim($value);
    }

    private function integer(mixed $value,int $min,int $max,string $field):int
    {
        if(is_int($value))$number=$value;
        elseif(is_string($value)&&preg_match('/^-?[0-9]+$/',trim($value))===1)$number=(int)trim($value);
        else throw new InvalidArgumentException($field.' must be an integer.');
        if($number<$min||$number>$max)throw new InvalidArgumentException($field.' is outside the allowed range.');
        return $number;
    }
}
