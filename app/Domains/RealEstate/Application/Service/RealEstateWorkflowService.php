<?php
declare(strict_types=1);

namespace Domains\RealEstate\Application\Service;

use DateTimeImmutable;
use Domains\Property\Application\Contract\PropertyInventoryCommandInterface;
use Domains\Property\Contract\PropertyReferencePort;
use Domains\RealEstate\Application\Contract\RealEstateRepositoryInterface;
use Domains\RealEstate\Application\Contract\SalesOpportunityReferenceInterface;
use Domains\RealEstate\Automation\Event\RealEstateDomainEvents;
use Domains\RealEstate\Automation\Event\RealEstateEventType;
use Domains\RealEstate\Domain\BrokerageProcess;
use Domains\RealEstate\Domain\Offer;
use Domains\RealEstate\Domain\Showing;
use InvalidArgumentException;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class RealEstateWorkflowService
{
    public function __construct(
        private RealEstateRepositoryInterface $repository,
        private SalesOpportunityReferenceInterface $sales,
        private PropertyReferencePort $properties,
        private PropertyInventoryCommandInterface $inventory,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function match(
        string $organizationId,
        int $actorId,
        int $opportunityId,
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

        $existing=$this->repository->findMatch($organizationId,$opportunityId,$propertyId);
        if($existing!==null)return $this->view($organizationId,$existing->id)+['replayed'=>true];

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

        $created=$this->transactions->transactional(function()use($case,$actorId,$metadata):bool{
            if(!$this->repository->createCase($case,$actorId))return false;
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::PROPERTY_MATCHED,
                $case->organizationId->value(),
                $case->id,
                ['opportunity_id'=>$case->opportunityId,'property_id'=>$case->propertyId,'inventory_id'=>$case->inventoryId],
                $metadata,
            ));
            return true;
        });

        return $this->view($organizationId,$caseId)+($created?[]:['replayed'=>true]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createOffer(string $organizationId,int $actorId,string $caseId,array $input,string $correlationId):array
    {
        $case=$this->case($organizationId,$caseId);
        $partyId=trim((string)($input['party_id']??''));
        if($partyId==='')throw new InvalidArgumentException('party_id is required.');
        $amountMinor=array_key_exists('amount_minor',$input)?(int)$input['amount_minor']:(int)round(((float)($input['amount']??0))*100);
        if($amountMinor<=0)throw new InvalidArgumentException('Offer amount must be positive.');
        $currency=strtoupper((string)($input['currency']??'USD'));
        $offerId=trim((string)($input['offer_id']??''))?:$this->id('OFR');

        $existing=$this->repository->findOffer($organizationId,$offerId);
        if($existing!==null){
            $this->assertOfferReplay($existing,$caseId,$partyId,$amountMinor,$currency);
            return $this->view($organizationId,$caseId)+['replayed'=>true];
        }

        $offer=new Offer($offerId,$case->organizationId,$case->propertyId,$partyId,new Money($amountMinor,$currency));
        $next=$case->transitionTo(BrokerageProcess::OFFERED);
        $metadata=$this->metadata($actorId,$correlationId);

        $created=$this->transactions->transactional(function()use($caseId,$offer,$next,$actorId,$metadata):bool{
            if(!$this->repository->createOffer($caseId,$offer,$actorId))return false;
            $this->repository->saveCase($next,$actorId);
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::OFFER_CREATED,
                $next->organizationId->value(),
                $next->id,
                ['offer_id'=>$offer->id,'amount_minor'=>$offer->amount->minorUnits(),'currency'=>$offer->amount->currency()],
                $metadata,
            ));
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
    public function scheduleViewing(string $organizationId,int $actorId,string $caseId,array $input,string $correlationId):array
    {
        $case=$this->case($organizationId,$caseId);
        $clientId=trim((string)($input['client_id']??''));
        $raw=trim((string)($input['scheduled_at']??''));
        if($clientId===''||$raw==='')throw new InvalidArgumentException('client_id and scheduled_at are required.');

        $showingId=trim((string)($input['showing_id']??''))?:$this->id('SHW');
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

        $created=$this->transactions->transactional(function()use($caseId,$showing,$scheduledAt,$notes,$next,$actorId,$metadata):bool{
            if(!$this->repository->createShowing($caseId,$showing,$scheduledAt,$notes,$actorId))return false;
            $this->repository->saveCase($next,$actorId);
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::VIEWING_SCHEDULED,
                $next->organizationId->value(),
                $next->id,
                ['showing_id'=>$showing->id,'scheduled_at'=>$scheduledAt->format(DATE_ATOM)],
                $metadata,
            ));
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
    public function reserve(string $organizationId,int $actorId,string $caseId,array $input,string $correlationId):array
    {
        $case=$this->case($organizationId,$caseId);
        if($case->inventoryId===null)throw new InvalidArgumentException('Matched case has no reservable inventory.');
        if($case->status===BrokerageProcess::RESERVED){
            return ['case'=>$this->view($organizationId,$caseId),'reservation'=>['replayed'=>true]];
        }

        $metadata=$this->metadata($actorId,$correlationId);
        try{
            $result=$this->transactions->transactional(function()use($case,$input,$actorId,$correlationId,$metadata):array{
                $reservation=$this->inventory->reserve(
                    $case->organizationId->value(),
                    $case->inventoryId,
                    [
                        'reservation_id'=>$input['reservation_id']??null,
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
                return $reservation;
            });
        }catch(InvalidArgumentException $exception){
            $fresh=$this->repository->findCase($organizationId,$caseId);
            if($fresh!==null&&$fresh->status===BrokerageProcess::RESERVED){
                return ['case'=>$this->view($organizationId,$caseId),'reservation'=>['replayed'=>true]];
            }
            throw $exception;
        }

        return ['case'=>$this->view($organizationId,$caseId),'reservation'=>$result];
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

    private function metadata(int $actorId,string $correlationId):EventMetadata
    {
        return new EventMetadata($correlationId,null,'USER',(string)$actorId);
    }

    private function stableId(string $value):string
    {
        return strtoupper(substr(hash('sha256',$value),0,20));
    }

    private function id(string $prefix):string
    {
        return $prefix.'-'.strtoupper(bin2hex(random_bytes(10)));
    }
}
