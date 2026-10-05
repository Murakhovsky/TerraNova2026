<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditAction;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditResourceType;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditTrail;
use Domains\CapitalMarkets\Application\Command\ActivateInstrument;
use Domains\CapitalMarkets\Application\Command\AddInstrumentIdentifier;
use Domains\CapitalMarkets\Application\Command\CreateInstrument;
use Domains\CapitalMarkets\Application\Command\CreateRelationship;
use Domains\CapitalMarkets\Application\Command\CreateVenue;
use Domains\CapitalMarkets\Application\Command\RegisterVenueInstrument;
use Domains\CapitalMarkets\Application\Command\SuspendInstrument;
use Domains\CapitalMarkets\Application\Command\UpdateInstrument;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsEventPublisherInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsFoundationBoundary;
use Domains\CapitalMarkets\Application\Query\FindInstrumentByIdentifier;
use Domains\CapitalMarkets\Application\Query\GetInstrument;
use Domains\CapitalMarkets\Application\Query\GetRelatedInstruments;
use Domains\CapitalMarkets\Application\Query\GetVenueInstruments;
use Domains\CapitalMarkets\Application\Query\ListInstruments;
use Domains\CapitalMarkets\Application\Query\ListRelationships;
use Domains\CapitalMarkets\Application\Query\ListVenues;
use Domains\CapitalMarkets\Domain\Contract\InstrumentRepository;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Contract\VenueRepository;
use Domains\CapitalMarkets\Domain\Event\InstrumentActivated;
use Domains\CapitalMarkets\Domain\Event\InstrumentCreated;
use Domains\CapitalMarkets\Domain\Event\InstrumentSuspended;
use Domains\CapitalMarkets\Domain\Event\InstrumentUpdated;
use Domains\CapitalMarkets\Domain\Event\RelationshipCreated;
use Domains\CapitalMarkets\Domain\Event\VenueCreated;
use Domains\CapitalMarkets\Domain\Event\VenueInstrumentRegistered;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStatus;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStrength;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifier;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifierType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentStatus;
use Domains\CapitalMarkets\Domain\Instrument\RelationshipId;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Currency;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Venue\VenueCapability;
use Domains\CapitalMarkets\Domain\Venue\VenueDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueType;
use InvalidArgumentException;

final readonly class CapitalMarketsFoundationService implements CapitalMarketsFoundationBoundary
{
    public function __construct(
        private InstrumentRepository $instruments,
        private RelationshipRepository $relationships,
        private VenueRepository $venues,
        private CapitalMarketsAuditTrail $audit,
        private CapitalMarketsEventPublisherInterface $events,
    ){}

    public function createInstrument(CreateInstrument $command):array
    {
        $now=new DateTimeImmutable();
        $id=InstrumentId::fromString($this->id($command->input['id']??null,'instrument'));
        $instrument=$this->instrument($id,null,$command->input,$now);
        $identifiers=$this->identifiers($command->input['identifiers']??null,$instrument->symbol);
        $this->instruments->save($command->organizationId,$instrument,$identifiers);
        $next=$this->instrumentArray($instrument,$identifiers);
        $this->audit->record($command->organizationId,$command->actorId,CapitalMarketsAuditAction::InstrumentCreated,
            CapitalMarketsAuditResourceType::Instrument,$id->value(),[],$next,$command->correlationId);
        $this->events->publish(new InstrumentCreated(
            $this->eventId(),$now,$command->organizationId,$id,
            ['symbol'=>$instrument->symbol,'family'=>$instrument->family->value,'status'=>$instrument->status->value],
        ));
        return $next;
    }

    public function updateInstrument(UpdateInstrument $command):array
    {
        $id=InstrumentId::fromString($command->instrumentId);
        $existing=$this->requireInstrument($command->organizationId,$id);
        $before=$this->instrumentArray($existing,$this->instruments->identifiers($command->organizationId,$id));
        $now=new DateTimeImmutable();
        $instrument=$this->instrument($id,$existing,$command->input,$now);
        $this->assertTransition($existing->status,$instrument->status);
        $identifiers=array_key_exists('identifiers',$command->input)
            ?$this->identifiers($command->input['identifiers'],$instrument->symbol)
            :$this->instruments->identifiers($command->organizationId,$id);
        if($instrument->status===InstrumentStatus::Active && $identifiers===[]){
            throw new DomainException('Active instrument requires at least one typed identifier.');
        }
        $this->instruments->save($command->organizationId,$instrument,$identifiers);
        $next=$this->instrumentArray($instrument,$identifiers);
        $action=$existing->status===$instrument->status
            ?CapitalMarketsAuditAction::InstrumentUpdated:CapitalMarketsAuditAction::InstrumentStatusChanged;
        $this->audit->record($command->organizationId,$command->actorId,$action,
            CapitalMarketsAuditResourceType::Instrument,$id->value(),$before,$next,$command->correlationId);
        $this->events->publish(new InstrumentUpdated($this->eventId(),$now,$command->organizationId,$id,['before'=>$before,'after'=>$next]));
        if($existing->status!==$instrument->status){
            if($instrument->status===InstrumentStatus::Active){
                $this->events->publish(new InstrumentActivated($this->eventId(),$now,$command->organizationId,$id));
            }elseif($instrument->status===InstrumentStatus::Suspended){
                $this->events->publish(new InstrumentSuspended($this->eventId(),$now,$command->organizationId,$id));
            }
        }
        return $next;
    }

    public function activateInstrument(ActivateInstrument $command):array
    {
        return $this->updateInstrument(new UpdateInstrument(
            $command->organizationId,$command->actorId,$command->correlationId,$command->instrumentId,['status'=>'ACTIVE']
        ));
    }

    public function suspendInstrument(SuspendInstrument $command):array
    {
        return $this->updateInstrument(new UpdateInstrument(
            $command->organizationId,$command->actorId,$command->correlationId,$command->instrumentId,['status'=>'SUSPENDED']
        ));
    }

    public function addInstrumentIdentifier(AddInstrumentIdentifier $command):array
    {
        $id=InstrumentId::fromString($command->instrumentId);
        $instrument=$this->requireInstrument($command->organizationId,$id);
        $before=$this->instruments->identifiers($command->organizationId,$id);
        $new=$this->identifier($command->identifier);
        foreach($before as $existing){
            if($existing->uniquenessKey()===$new->uniquenessKey())throw new DomainException('Instrument identifier already exists.');
        }
        $identifiers=[...$before,$new];
        $this->instruments->save($command->organizationId,$instrument,$identifiers);
        $next=$this->instrumentArray($instrument,$identifiers);
        $this->audit->record($command->organizationId,$command->actorId,CapitalMarketsAuditAction::InstrumentUpdated,
            CapitalMarketsAuditResourceType::Instrument,$id->value(),
            ['identifiers'=>array_map($this->identifierArray(...),$before)],$next,$command->correlationId);
        $this->events->publish(new InstrumentUpdated(
            $this->eventId(),new DateTimeImmutable(),$command->organizationId,$id,['identifier_added'=>$this->identifierArray($new)]
        ));
        return $next;
    }

    public function createRelationship(CreateRelationship $command):array
    {
        $source=InstrumentId::fromString($this->required($command->input,'source_instrument'));
        $target=InstrumentId::fromString($this->required($command->input,'target_instrument'));
        $sourceInstrument=$this->requireInstrument($command->organizationId,$source);
        $targetInstrument=$this->requireInstrument($command->organizationId,$target);
        if($sourceInstrument->status!==InstrumentStatus::Active||$targetInstrument->status!==InstrumentStatus::Active){
            throw new DomainException('Economic relationships can only be created between ACTIVE instruments.');
        }

        $relationship=new EconomicRelationship(
            RelationshipId::fromString($this->id($command->input['id']??null,'relationship')),
            $source,$target,
            EconomicRelationshipType::from(strtoupper($this->required($command->input,'type'))),
            EconomicRelationshipStrength::from(strtoupper((string)($command->input['strength']??'DIRECT'))),
            isset($command->input['effective_from'])?new DateTimeImmutable((string)$command->input['effective_from']):new DateTimeImmutable(),
            isset($command->input['effective_to'])&&$command->input['effective_to']!==''?new DateTimeImmutable((string)$command->input['effective_to']):null,
            EconomicRelationshipStatus::from(strtoupper((string)($command->input['status']??'ACTIVE'))),
            $this->metadata($command->input['metadata']??[]),
        );
        $this->relationships->save($command->organizationId,$relationship);
        $next=$this->relationshipArray($command->organizationId,$relationship);
        $this->audit->record($command->organizationId,$command->actorId,CapitalMarketsAuditAction::RelationshipCreated,
            CapitalMarketsAuditResourceType::Relationship,$relationship->id->value(),[],$next,$command->correlationId);
        $this->events->publish(new RelationshipCreated(
            $this->eventId(),new DateTimeImmutable(),$command->organizationId,$relationship->id,
            ['source'=>$source->value(),'target'=>$target->value(),'type'=>$relationship->type->value,'strength'=>$relationship->strength->value],
        ));
        return $next;
    }

    public function createVenue(CreateVenue $command):array
    {
        $venue=new VenueDescriptor(
            VenueId::fromString($this->id($command->input['id']??null,'venue')),
            $this->required($command->input,'name'),
            strtoupper($this->required($command->input,'code')),
            VenueType::from(strtolower($this->required($command->input,'type'))),
            VenueStatus::from(strtoupper((string)($command->input['status']??'ACTIVE'))),
            $this->nullable($command->input['jurisdiction']??null),
            (string)($command->input['timezone']??'UTC'),
            $this->nullable($command->input['base_url_reference']??null),
            $this->metadata($command->input['metadata']??[]),
        );
        $capabilities=[];
        foreach($command->input['capabilities']??[] as $capability){
            if(!is_string($capability))throw new InvalidArgumentException('Venue capability must be a string.');
            $capabilities[]=VenueCapability::from(strtoupper($capability));
        }
        $this->venues->save($command->organizationId,$venue,$capabilities);
        $next=$this->venueArray($command->organizationId,$venue);
        $this->audit->record($command->organizationId,$command->actorId,CapitalMarketsAuditAction::VenueCreated,
            CapitalMarketsAuditResourceType::Venue,$venue->id->value(),[],$next,$command->correlationId);
        $this->events->publish(new VenueCreated(
            $this->eventId(),new DateTimeImmutable(),$command->organizationId,$venue->id,
            ['code'=>$venue->code,'type'=>$venue->type->value,'status'=>$venue->status->value],
        ));
        return $next;
    }

    public function registerVenueInstrument(RegisterVenueInstrument $command):array
    {
        $venueId=VenueId::fromString($command->venueId);
        $venue=$this->venues->get($command->organizationId,$venueId);
        if($venue===null)throw new DomainException('Venue not found.');
        if($venue->status!==VenueStatus::Active)throw new DomainException('Cannot register an instrument on an inactive venue.');
        $instrumentId=InstrumentId::fromString($this->required($command->input,'instrument_id'));
        $instrument=$this->requireInstrument($command->organizationId,$instrumentId);
        if($instrument->status!==InstrumentStatus::Active)throw new DomainException('Cannot register an inactive instrument on a venue.');

        $mapping=new VenueInstrument(
            $venueId,$instrumentId,$this->required($command->input,'venue_symbol'),
            VenueInstrumentStatus::from(strtoupper((string)($command->input['status']??'ACTIVE'))),
            (int)($command->input['price_precision']??2),(int)($command->input['quantity_precision']??8),
            $this->decimalOrNull($command->input['minimum_quantity']??null),
            $this->decimalOrNull($command->input['minimum_notional']??null),
            $this->metadata($command->input['metadata']??[]),
        );
        $this->venues->registerInstrument($command->organizationId,$mapping);
        $next=$this->mappingArray($command->organizationId,$mapping);
        $resourceId=$venueId->value().'|'.$instrumentId->value();
        $this->audit->record($command->organizationId,$command->actorId,CapitalMarketsAuditAction::VenueInstrumentRegistered,
            CapitalMarketsAuditResourceType::VenueInstrument,$resourceId,[],$next,$command->correlationId);
        $this->events->publish(new VenueInstrumentRegistered(
            $this->eventId(),new DateTimeImmutable(),$command->organizationId,$venueId,$instrumentId,$mapping->venueSymbol
        ));
        return $next;
    }

    public function getInstrument(GetInstrument $query):?array
    {
        $id=InstrumentId::fromString($query->instrumentId);
        $instrument=$this->instruments->get($query->organizationId,$id);
        if($instrument===null)return null;
        $view=$this->instrumentArray($instrument,$this->instruments->identifiers($query->organizationId,$id));
        $view['relationships']=array_map(
            fn(EconomicRelationship $r):array=>$this->relationshipArray($query->organizationId,$r),
            $this->relationships->forInstrument($query->organizationId,$id)
        );
        $view['venues']=array_map(
            fn(VenueInstrument $m):array=>$this->mappingArray($query->organizationId,$m),
            $this->venues->venuesForInstrument($query->organizationId,$id)
        );
        return $view;
    }

    public function listInstruments(ListInstruments $query):array
    {
        return array_map(
            fn(InstrumentDescriptor $i):array=>$this->instrumentArray($i,$this->instruments->identifiers($query->organizationId,$i->id)),
            $this->instruments->list($query->organizationId,$query->filters,$query->limit)
        );
    }

    public function findInstrumentByIdentifier(FindInstrumentByIdentifier $query):?array
    {
        $identifier=new InstrumentIdentifier(
            InstrumentIdentifierType::from(strtoupper($query->type)),$query->value,$query->source
        );
        $instrument=$this->instruments->findByIdentifier($query->organizationId,$identifier);
        return $instrument===null?null:$this->getInstrument(new GetInstrument($query->organizationId,$instrument->id->value()));
    }

    public function getRelatedInstruments(GetRelatedInstruments $query):array
    {
        $id=InstrumentId::fromString($query->instrumentId);
        $this->requireInstrument($query->organizationId,$id);
        return array_map(
            fn(EconomicRelationship $r):array=>$this->relationshipArray($query->organizationId,$r),
            $this->relationships->forInstrument($query->organizationId,$id)
        );
    }

    public function listRelationships(ListRelationships $query):array
    {
        return array_map(
            fn(EconomicRelationship $r):array=>$this->relationshipArray($query->organizationId,$r),
            $this->relationships->list($query->organizationId,$query->limit)
        );
    }

    public function listVenues(ListVenues $query):array
    {
        return array_map(fn(VenueDescriptor $v):array=>$this->venueArray($query->organizationId,$v),
            $this->venues->list($query->organizationId,$query->limit));
    }

    public function getVenueInstruments(GetVenueInstruments $query):array
    {
        $venueId=VenueId::fromString($query->venueId);
        if($this->venues->get($query->organizationId,$venueId)===null)throw new DomainException('Venue not found.');
        return array_map(fn(VenueInstrument $m):array=>$this->mappingArray($query->organizationId,$m),
            $this->venues->instruments($query->organizationId,$venueId));
    }

    /** @param array<string,mixed> $input */
    private function instrument(InstrumentId $id,?InstrumentDescriptor $existing,array $input,DateTimeImmutable $now):InstrumentDescriptor
    {
        $symbol=(string)($input['symbol']??$existing?->symbol??'');
        $canonical=(string)($input['canonical_symbol']??$existing?->canonicalSymbol??strtoupper($symbol));
        $currency=$input['currency']??$existing?->currency?->value();
        $quote=$input['quote_asset']??$existing?->quoteAsset?->value();
        return new InstrumentDescriptor(
            $id,$symbol,$canonical,(string)($input['name']??$existing?->name??''),
            isset($input['family'])?InstrumentFamily::from(strtolower((string)$input['family'])):($existing?->family??throw new InvalidArgumentException('Instrument family is required.')),
            isset($input['status'])?InstrumentStatus::from(strtoupper((string)$input['status'])):($existing?->status??InstrumentStatus::Draft),
            $this->nullable($currency)===null?null:new Currency((string)$currency),
            $this->nullable($quote)===null?null:new AssetCode((string)$quote),
            array_key_exists('issuer_reference',$input)?$this->nullable($input['issuer_reference']):$existing?->issuerReference,
            array_key_exists('jurisdiction',$input)?$this->nullable($input['jurisdiction']):$existing?->jurisdiction,
            array_key_exists('primary_venue_reference',$input)?$this->nullable($input['primary_venue_reference']):$existing?->primaryVenueReference,
            array_key_exists('metadata',$input)?$this->metadata($input['metadata']):($existing?->metadata??[]),
            $existing?->createdAt??$now,$now,
        );
    }

    /** @return list<InstrumentIdentifier> */
    private function identifiers(mixed $input,string $symbol):array
    {
        if($input===null)return [new InstrumentIdentifier(InstrumentIdentifierType::Ticker,$symbol)];
        if(!is_array($input))throw new InvalidArgumentException('Instrument identifiers must be an array.');
        $result=[];
        foreach($input as $row){
            if(!is_array($row))throw new InvalidArgumentException('Instrument identifier must be an object.');
            $identifier=$this->identifier($row);
            if(isset($result[$identifier->uniquenessKey()]))throw new DomainException('Duplicate instrument identifier.');
            $result[$identifier->uniquenessKey()]=$identifier;
        }
        return array_values($result);
    }

    /** @param array<string,mixed> $row */
    private function identifier(array $row):InstrumentIdentifier
    {
        return new InstrumentIdentifier(
            InstrumentIdentifierType::from(strtoupper($this->required($row,'type'))),
            $this->required($row,'value'),
            $this->nullable($row['source']??null),
        );
    }

    private function requireInstrument(string $organizationId,InstrumentId $id):InstrumentDescriptor
    {
        return $this->instruments->get($organizationId,$id)??throw new DomainException('Instrument not found.');
    }

    private function assertTransition(InstrumentStatus $from,InstrumentStatus $to):void
    {
        if($from===$to)return;
        $allowed=match($from){
            InstrumentStatus::Draft=>[InstrumentStatus::Active],
            InstrumentStatus::Active=>[InstrumentStatus::Suspended,InstrumentStatus::Delisted],
            InstrumentStatus::Suspended=>[InstrumentStatus::Active,InstrumentStatus::Delisted],
            InstrumentStatus::Unknown=>[InstrumentStatus::Draft],
            InstrumentStatus::Delisted=>[],
        };
        if(!in_array($to,$allowed,true))throw new DomainException('Invalid instrument status transition: '.$from->value.' -> '.$to->value);
    }

    /** @return array<string,mixed> */
    private function instrumentArray(InstrumentDescriptor $instrument,array $identifiers):array
    {
        return [
            'id'=>$instrument->id->value(),'symbol'=>$instrument->symbol,'canonical_symbol'=>$instrument->canonicalSymbol,
            'name'=>$instrument->name,'family'=>$instrument->family->value,'status'=>$instrument->status->value,
            'currency'=>$instrument->currency?->value(),'quote_asset'=>$instrument->quoteAsset?->value(),
            'issuer_reference'=>$instrument->issuerReference,'jurisdiction'=>$instrument->jurisdiction,
            'primary_venue_reference'=>$instrument->primaryVenueReference,'metadata'=>$instrument->metadata,
            'identifiers'=>array_map($this->identifierArray(...),$identifiers),
            'created_at'=>$instrument->createdAt->format(DATE_ATOM),'updated_at'=>$instrument->updatedAt->format(DATE_ATOM),
        ];
    }

    /** @return array{type:string,value:string,source:?string} */
    private function identifierArray(InstrumentIdentifier $identifier):array
    {
        return ['type'=>$identifier->type->value,'value'=>$identifier->value,'source'=>$identifier->source];
    }

    /** @return array<string,mixed> */
    private function relationshipArray(string $organizationId,EconomicRelationship $relationship):array
    {
        $source=$this->instruments->get($organizationId,$relationship->sourceInstrument);
        $target=$this->instruments->get($organizationId,$relationship->targetInstrument);
        return [
            'id'=>$relationship->id->value(),
            'source_instrument'=>$source===null?['id'=>$relationship->sourceInstrument->value()]:$this->instrumentSummary($source),
            'target_instrument'=>$target===null?['id'=>$relationship->targetInstrument->value()]:$this->instrumentSummary($target),
            'type'=>$relationship->type->value,'strength'=>$relationship->strength->value,'status'=>$relationship->status->value,
            'effective_from'=>$relationship->effectiveFrom->format(DATE_ATOM),
            'effective_to'=>$relationship->effectiveTo?->format(DATE_ATOM),'metadata'=>$relationship->metadata,
        ];
    }

    /** @return array<string,mixed> */
    private function venueArray(string $organizationId,VenueDescriptor $venue):array
    {
        return [
            'id'=>$venue->id->value(),'name'=>$venue->name,'code'=>$venue->code,'type'=>$venue->type->value,
            'status'=>$venue->status->value,'jurisdiction'=>$venue->jurisdiction,'timezone'=>$venue->timezone,
            'base_url_reference'=>$venue->baseUrlReference,'metadata'=>$venue->metadata,
            'capabilities'=>array_map(static fn(VenueCapability $c):string=>$c->value,$this->venues->capabilities($organizationId,$venue->id)),
            'supported_instrument_count'=>count($this->venues->instruments($organizationId,$venue->id)),
        ];
    }

    /** @return array<string,mixed> */
    private function mappingArray(string $organizationId,VenueInstrument $mapping):array
    {
        $venue=$this->venues->get($organizationId,$mapping->venueId);
        $instrument=$this->instruments->get($organizationId,$mapping->instrumentId);
        return [
            'venue'=>$venue===null?['id'=>$mapping->venueId->value()]:['id'=>$venue->id->value(),'name'=>$venue->name,'code'=>$venue->code],
            'instrument'=>$instrument===null?['id'=>$mapping->instrumentId->value()]:$this->instrumentSummary($instrument),
            'venue_symbol'=>$mapping->venueSymbol,'status'=>$mapping->status->value,
            'price_precision'=>$mapping->pricePrecision,'quantity_precision'=>$mapping->quantityPrecision,
            'minimum_quantity'=>$mapping->minimumQuantity?->value(),'minimum_notional'=>$mapping->minimumNotional?->value(),
            'metadata'=>$mapping->metadata,
        ];
    }

    /** @return array{id:string,symbol:string,name:string,family:string,status:string} */
    private function instrumentSummary(InstrumentDescriptor $instrument):array
    {
        return ['id'=>$instrument->id->value(),'symbol'=>$instrument->symbol,'name'=>$instrument->name,
            'family'=>$instrument->family->value,'status'=>$instrument->status->value];
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key):string
    {
        $value=trim((string)($input[$key]??''));
        if($value==='')throw new InvalidArgumentException($key.' is required.');
        return $value;
    }

    private function nullable(mixed $value):?string
    {
        if($value===null)return null;
        $value=trim((string)$value);
        return $value===''?null:$value;
    }

    /** @return array<string,mixed> */
    private function metadata(mixed $value):array
    {
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('Metadata must be a JSON object.');
        InstrumentDescriptor::assertMetadata($value);
        return $value;
    }

    private function decimalOrNull(mixed $value):?Decimal
    {
        $value=$this->nullable($value);
        return $value===null?null:Decimal::fromString($value);
    }

    private function id(mixed $provided,string $prefix):string
    {
        $provided=$this->nullable($provided);
        return $provided??($prefix.'_'.bin2hex(random_bytes(12)));
    }

    private function eventId():string{return 'cm_evt_'.bin2hex(random_bytes(16));}
}
