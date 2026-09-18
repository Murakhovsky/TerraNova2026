<?php
declare(strict_types=1);

namespace Domains\RealEstate\Application\Service;

use DateTimeImmutable;
use Domains\Property\Application\Contract\PropertyInventoryCommandInterface;
use Domains\Property\Contract\PropertyBrokerageReferencePort;
use Domains\RealEstate\Application\Contract\RealEstateRepositoryInterface;
use Domains\RealEstate\Application\Contract\RealEstateMutationReceiptInterface;
use Domains\RealEstate\Application\Contract\SalesOpportunityReferenceInterface;
use Domains\RealEstate\Automation\Event\RealEstateDomainEvents;
use Domains\RealEstate\Automation\Event\RealEstateEventType;
use Domains\RealEstate\Domain\BrokerageProcess;
use Domains\RealEstate\Domain\Offer;
use Domains\RealEstate\Domain\Showing;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class RealEstateWorkflowService
{
    public function __construct(
        private RealEstateRepositoryInterface $repository,
        private RealEstateMutationReceiptInterface $receipts,
        private SalesOpportunityReferenceInterface $sales,
        private PropertyBrokerageReferencePort $properties,
        private PropertyInventoryCommandInterface $inventory,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function match(
        string $organizationId,
        int $actorId,
        int $opportunityId,
        string $idempotencyKey,
        array $input,
        string $correlationId,
    ): array {
        if (!$this->sales->exists($organizationId, $opportunityId)) {
            throw new InvalidArgumentException('Sales opportunity was not found in the current organization.');
        }

        $reference=trim((string)($input['property_id']??''));
        if($reference==='')throw new InvalidArgumentException('property_id is required.');
        $presentation=$this->properties->getPropertyPresentation($organizationId,$reference);
        if($presentation===null)throw new InvalidArgumentException('Property was not found.');

        $property=is_array($presentation['property']??null)?$presentation['property']:[];
        $propertyId=trim((string)($property['asset_id']??''));
        if($propertyId==='')throw new InvalidArgumentException('Property has no canonical asset id.');

        $inventoryId=trim((string)($input['inventory_id']??($presentation['inventory']['inventory_id']??'')));
        if($inventoryId!==''){
            $inventory=$this->properties->getInventorySnapshot($organizationId,$inventoryId);
            if($inventory===null||(string)($inventory['asset_id']??'')!==$propertyId){
                throw new InvalidArgumentException('Inventory does not belong to the matched Property.');
            }
        }

        $caseId='RE-'.$this->stableId($organizationId.':'.$opportunityId.':'.$propertyId);
        $case=new BrokerageProcess(
            $caseId,
            OrganizationId::fromString($organizationId),
            $opportunityId,
            $propertyId,
            $inventoryId!==''?$inventoryId:null,
            trim((string)($input['subject']??'Property match')),
        );
        $metadata=$this->metadata($actorId,$correlationId);
        $fingerprint=$this->fingerprint([
            'opportunity_id'=>$opportunityId,
            'property_id'=>$propertyId,
            'inventory_id'=>$case->inventoryId,
            'subject'=>$case->subject,
        ]);

        $created=$this->transactions->transactional(function()use($case,$actorId,$metadata,$idempotencyKey,$fingerprint):bool{
            if(!$this->receipts->claim($case->organizationId->value(),'property_match',$idempotencyKey,$fingerprint))return false;
            if(!$this->repository->createCase($case,$actorId))return false;
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::PROPERTY_MATCHED,
                $case->organizationId->value(),
                $case->id,
                ['opportunity_id'=>$case->opportunityId,'property_id'=>$case->propertyId,'inventory_id'=>$case->inventoryId],
                $metadata,
            ));
            $this->appendAudit($case,'property_matched',$actorId,$metadata->correlationId,[
                'opportunity_id'=>$case->opportunityId,
                'property_id'=>$case->propertyId,
                'inventory_id'=>$case->inventoryId,
                'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
            ]);
            return true;
        });

        return $this->view($organizationId,$caseId)+($created?[]:['replayed'=>true]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createOffer(string $organizationId,int $actorId,string $caseId,string $idempotencyKey,array $input,string $correlationId):array
    {
        $case=$this->case($organizationId,$caseId);
        $partyId=trim((string)($input['party_id']??''));
        if($partyId==='')throw new InvalidArgumentException('party_id is required.');
        $amountMinor=array_key_exists('amount_minor',$input)?(int)$input['amount_minor']:(int)round(((float)($input['amount']??0))*100);
        if($amountMinor<=0)throw new InvalidArgumentException('Offer amount must be positive.');
        $currency=strtoupper((string)($input['currency']??'USD'));
        $offerId='OFR-'.$this->stableId($organizationId.':offer:'.$idempotencyKey);

        $existing=$this->repository->findOffer($organizationId,$offerId);
        if($existing!==null){
            $this->assertOfferReplay($existing,$caseId,$partyId,$amountMinor,$currency);
            return $this->view($organizationId,$caseId)+['replayed'=>true];
        }

        $offer=new Offer($offerId,$case->organizationId,$case->propertyId,$partyId,new Money($amountMinor,$currency));
        $next=$case->transitionTo(BrokerageProcess::OFFERED);
        $metadata=$this->metadata($actorId,$correlationId);
        $fingerprint=$this->fingerprint([
            'case_id'=>$caseId,
            'party_id'=>$partyId,
            'amount_minor'=>$amountMinor,
            'currency'=>$currency,
        ]);

        $created=$this->transactions->transactional(function()use($caseId,$offer,$next,$actorId,$metadata,$idempotencyKey,$fingerprint):bool{
            if(!$this->receipts->claim($next->organizationId->value(),'offer',$idempotencyKey,$fingerprint))return false;
            if(!$this->repository->createOffer($caseId,$offer,$actorId))return false;
            $this->repository->saveCase($next,$actorId);
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::OFFER_CREATED,
                $next->organizationId->value(),
                $next->id,
                ['offer_id'=>$offer->id,'amount_minor'=>$offer->amount->minorUnits(),'currency'=>$offer->amount->currency()],
                $metadata,
            ));
            $this->appendAudit($next,'offer_created',$actorId,$metadata->correlationId,[
                'offer_id'=>$offer->id,
                'amount_minor'=>$offer->amount->minorUnits(),
                'currency'=>$offer->amount->currency(),
                'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
            ]);
            return true;
        });

        if(!$created){
            $existing=$this->repository->findOffer($organizationId,$offerId);
            if($existing===null)throw new InvalidArgumentException('Offer idempotency race could not be resolved.');
            $this->assertOfferReplay($existing,$caseId,$partyId,$amountMinor,$currency);
        }
        return $this->view($organizationId,$caseId)+($created?[]:['replayed'=>true]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function scheduleViewing(string $organizationId,int $actorId,string $caseId,string $idempotencyKey,array $input,string $correlationId):array
    {
        $case=$this->case($organizationId,$caseId);
        $clientId=trim((string)($input['client_id']??''));
        $raw=trim((string)($input['scheduled_at']??''));
        if($clientId===''||$raw==='')throw new InvalidArgumentException('client_id and scheduled_at are required.');

        $showingId='SHW-'.$this->stableId($organizationId.':viewing:'.$idempotencyKey);
        $scheduledAt=new DateTimeImmutable($raw);
        $notes=trim((string)($input['notes']??''))?:null;

        $existing=$this->repository->findShowing($organizationId,$showingId);
        if($existing!==null){
            $this->assertShowingReplay($existing,$caseId,$clientId,$scheduledAt);
            return $this->view($organizationId,$caseId)+['replayed'=>true];
        }

        $showing=new Showing($showingId,$case->organizationId,$case->propertyId,$clientId);
        $next=$case->transitionTo(BrokerageProcess::VIEWING);
        $metadata=$this->metadata($actorId,$correlationId);
        $fingerprint=$this->fingerprint([
            'case_id'=>$caseId,
            'client_id'=>$clientId,
            'scheduled_at'=>$scheduledAt->format(DATE_ATOM),
            'notes'=>$notes,
        ]);

        $created=$this->transactions->transactional(function()use($caseId,$showing,$scheduledAt,$notes,$next,$actorId,$metadata,$idempotencyKey,$fingerprint):bool{
            if(!$this->receipts->claim($next->organizationId->value(),'viewing',$idempotencyKey,$fingerprint))return false;
            if(!$this->repository->createShowing($caseId,$showing,$scheduledAt,$notes,$actorId))return false;
            $this->repository->saveCase($next,$actorId);
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::VIEWING_SCHEDULED,
                $next->organizationId->value(),
                $next->id,
                ['showing_id'=>$showing->id,'scheduled_at'=>$scheduledAt->format(DATE_ATOM)],
                $metadata,
            ));
            $this->appendAudit($next,'viewing_scheduled',$actorId,$metadata->correlationId,[
                'showing_id'=>$showing->id,
                'scheduled_at'=>$scheduledAt->format(DATE_ATOM),
                'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
            ]);
            return true;
        });

        if(!$created){
            $existing=$this->repository->findShowing($organizationId,$showingId);
            if($existing===null)throw new InvalidArgumentException('Viewing idempotency race could not be resolved.');
            $this->assertShowingReplay($existing,$caseId,$clientId,$scheduledAt);
        }
        return $this->view($organizationId,$caseId)+($created?[]:['replayed'=>true]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reserve(string $organizationId,int $actorId,string $caseId,string $idempotencyKey,array $input,string $correlationId):array
    {
        $case=$this->case($organizationId,$caseId);
        if($case->inventoryId===null)throw new InvalidArgumentException('Matched case has no reservable inventory.');

        $metadata=$this->metadata($actorId,$correlationId);
        $reservationId='RSV-'.$this->stableId($organizationId.':reservation:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'case_id'=>$caseId,
            'inventory_id'=>$case->inventoryId,
            'expires_at'=>$input['expires_at']??null,
            'reason'=>$input['reason']??'brokerage_reservation',
        ]);
        $result=$this->transactions->transactional(function()use($case,$input,$actorId,$correlationId,$metadata,$reservationId,$idempotencyKey,$fingerprint):?array{
            if(!$this->receipts->claim($case->organizationId->value(),'reservation',$idempotencyKey,$fingerprint))return null;
            if($case->status===BrokerageProcess::RESERVED)return null;
            $reservation=$this->inventory->reserve(
                $case->organizationId->value(),
                $case->inventoryId,
                [
                    'reservation_id'=>$reservationId,
                    'reserved_for_reference'=>'real_estate_case:'.$case->id,
                    'expires_at'=>$input['expires_at']??null,
                    'reason'=>$input['reason']??'brokerage_reservation',
                ],
                (string)$actorId,
                $correlationId,
            );
            $next=$case->transitionTo(BrokerageProcess::RESERVED);
            $this->repository->saveCase($next,$actorId);
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::PROPERTY_RESERVED,
                $next->organizationId->value(),
                $next->id,
                ['inventory_id'=>$next->inventoryId,'reservation_id'=>$reservation['reservation_id']??null],
                $metadata,
            ));
            $this->appendAudit($next,'property_reserved',$actorId,$metadata->correlationId,[
                'inventory_id'=>$next->inventoryId,
                'reservation_id'=>$reservation['reservation_id']??null,
                'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
            ]);
            return $reservation;
        });

        return $result===null
            ?['case'=>$this->view($organizationId,$caseId),'reservation'=>['replayed'=>true]]
            :['case'=>$this->view($organizationId,$caseId),'reservation'=>$result];
    }

    /** @return array<string,mixed> */
    public function view(string $organizationId,string $caseId):array
    {
        return $this->repository->view($organizationId,$caseId)
            ??throw new InvalidArgumentException('RealEstate brokerage case was not found.');
    }

    private function case(string $organizationId,string $caseId):BrokerageProcess
    {
        return $this->repository->findCase($organizationId,$caseId)
            ??throw new InvalidArgumentException('RealEstate brokerage case was not found.');
    }

    /** @param array<string,mixed> $existing */
    private function assertOfferReplay(array $existing,string $caseId,string $partyId,int $amountMinor,string $currency):void
    {
        if((string)($existing['case_id']??'')!==$caseId
            ||(string)($existing['party_id']??'')!==$partyId
            ||(int)($existing['amount_minor']??0)!==$amountMinor
            ||strtoupper((string)($existing['currency']??''))!==$currency){
            throw new InvalidArgumentException('Idempotency key was reused with a different Offer payload.');
        }
    }

    /** @param array<string,mixed> $existing */
    private function assertShowingReplay(array $existing,string $caseId,string $clientId,DateTimeImmutable $scheduledAt):void
    {
        $stored=new DateTimeImmutable((string)($existing['scheduled_at']??''));
        if((string)($existing['case_id']??'')!==$caseId
            ||(string)($existing['client_id']??'')!==$clientId
            ||$stored->getTimestamp()!==$scheduledAt->getTimestamp()){
            throw new InvalidArgumentException('Idempotency key was reused with a different Viewing payload.');
        }
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(BrokerageProcess $case,string $action,int $actorId,string $correlationId,array $data):void
    {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),
            $case->organizationId->value(),
            'real_estate.mutation',
            'USER',
            (string)$actorId,
            'brokerage_case',
            $case->id,
            null,
            ['action'=>$action,...$data],
            $correlationId,
            new DateTimeImmutable(),
        ));
    }

    private function metadata(int $actorId,string $correlationId):EventMetadata
    {
        return new EventMetadata($correlationId,null,'USER',(string)$actorId);
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value):string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function stableId(string $value):string
    {
        return strtoupper(substr(hash('sha256',$value),0,20));
    }
}
