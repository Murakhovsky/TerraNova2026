<?php
declare(strict_types=1);

namespace Domains\Property\Application\Service;

use DateTimeImmutable;
use Domains\Property\Application\Contract\PropertyCanonicalRuntimeRepositoryInterface;
use Domains\Property\Application\Contract\PropertyCompatibilityProjectionInterface;
use Domains\Property\Automation\Event\InventoryDomainEvents;
use Domains\Property\Automation\Event\ListingDomainEvents;
use Domains\Property\Automation\Event\PropertyDomainEvents;
use Domains\Property\Model\InventoryItem;
use Domains\Property\Model\InventoryReservation;
use Domains\Property\Model\InventoryStatus;
use Domains\Property\Model\InventoryTransactionType;
use Domains\Property\Model\Listing;
use Domains\Property\Model\ListingStatus;
use Domains\Property\Model\PropertyAsset;
use Domains\Property\Model\PropertyAssetKind;
use Domains\Property\Model\PropertyLifecycle;
use Domains\Property\Model\PropertyLocation;
use Domains\Property\Model\PropertyType;
use Domains\Property\Model\Publication;
use Domains\Property\Model\PublicationState;
use InvalidArgumentException;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class PropertyCanonicalRuntimeService
{
    public function __construct(
        private PropertyCanonicalRuntimeRepositoryInterface $repository,
        private PropertyCompatibilityProjectionInterface $compatibility,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {}

    public function snapshot(string $organizationId, string $assetId): array
    {
        $asset=$this->asset($organizationId,$assetId); $inventory=$this->repository->primaryInventoryForAsset($organizationId,$assetId);
        $listing=$inventory!==null?$this->repository->primaryListingForInventory($organizationId,$inventory->inventoryId):null;
        $publication=$listing!==null?$this->repository->findPublicationForChannel($organizationId,$listing->listingId,'estatebook'):null;
        return ['asset'=>$asset->toArray(),'inventory'=>$inventory?->toArray(),'listing'=>$listing!==null?$this->listingArray($listing):null,
            'publication'=>$publication!==null?['publication_id'=>$publication->publicationId,'listing_id'=>$publication->listingId,'channel'=>$publication->channelCode,
                'state'=>$publication->state->value,'external_id'=>$publication->externalId,'external_url'=>$publication->externalUrl,
                'published_at'=>$publication->publishedAt?->format(DATE_ATOM)]:null];
    }

    public function registerAsset(string $organizationId,array $input,?string $actorId=null,?string $correlationId=null): array
    {
        $asset=$this->assetFromInput($organizationId,$input); if($this->repository->findAsset($organizationId,$asset->assetId)!==null)throw new InvalidArgumentException('Property asset already exists.');
        $meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($asset,$meta){$event=PropertyDomainEvents::assetRegistered($asset,$meta);$this->repository->saveAsset($asset);$this->events->publish($event);
            return ['asset'=>$asset->toArray(),'legacy_property_id'=>$this->compatibility->sync($asset->organizationId,$asset->assetId)];});
    }

    public function updateAsset(string $organizationId,string $assetId,array $input,?string $actorId=null,?string $correlationId=null): array
    {
        $before=$this->asset($organizationId,$assetId);$after=$this->patchAsset($before,$input);$meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($before,$after,$meta){$this->repository->saveAsset($after);foreach(PropertyDomainEvents::changes($before,$after,$meta)as$event)$this->events->publish($event);
            return ['asset'=>$after->toArray(),'legacy_property_id'=>$this->compatibility->sync($after->organizationId,$after->assetId)];});
    }

    public function changeLifecycle(string $organizationId,string $assetId,string $lifecycle,?string $actorId=null,?string $correlationId=null): array
    {
        $before=$this->asset($organizationId,$assetId);$after=$before->changeLifecycle(PropertyLifecycle::from($lifecycle));$meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($before,$after,$meta){$this->repository->saveAsset($after);$this->events->publish(PropertyDomainEvents::lifecycleChanged($after->organizationId,$after->assetId,$before->lifecycle,$after->lifecycle,$meta));
            $this->compatibility->sync($after->organizationId,$after->assetId);return ['asset'=>$after->toArray()];});
    }

    public function createInventory(string $organizationId,string $assetId,array $input,?string $actorId=null,?string $correlationId=null): array
    {
        $this->asset($organizationId,$assetId);$item=$this->inventoryFromInput($organizationId,$assetId,$input);if($this->repository->findInventory($organizationId,$item->inventoryId)!==null)throw new InvalidArgumentException('Inventory item already exists.');
        $meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($item,$meta){$event=InventoryDomainEvents::created($item,$meta);$this->repository->saveInventory($item,$event->id,$event->id,'created');$this->events->publish($event);
            $this->compatibility->sync($item->organizationId,$item->propertyAssetId);return $item->toArray();});
    }

    public function changeInventoryPrice(string $organizationId,string $inventoryId,?float $amount,?string $currency=null,?string $period=null,?string $actorId=null,?string $correlationId=null): array
    {
        $before=$this->inventory($organizationId,$inventoryId);$after=$before->changePrice($amount,$period,$currency!==null?strtoupper($currency):null);$meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($before,$after,$meta){$event=InventoryDomainEvents::priceChanged($before,$after,$meta);$this->repository->saveInventory($after,$event->id);$this->events->publish($event);
            $this->compatibility->sync($after->organizationId,$after->propertyAssetId);return $after->toArray();});
    }

    public function changeInventoryStatus(string $organizationId,string $inventoryId,string $status,?string $reason=null,?string $actorId=null,?string $correlationId=null): array
    {
        $before=$this->inventory($organizationId,$inventoryId);$after=$before->changeStatus(InventoryStatus::from($status));if($before->status->value===$after->status->value)return$after->toArray();$meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($before,$after,$reason,$meta){$event=InventoryDomainEvents::statusChanged($after,$before->status,$after->status,$meta);$this->repository->saveInventory($after,null,$event->id,$reason);$this->events->publish($event);
            $this->compatibility->sync($after->organizationId,$after->propertyAssetId);return$after->toArray();});
    }

    public function reserveInventory(string $organizationId,string $inventoryId,array $input,?string $actorId=null,?string $correlationId=null): array
    {
        $meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($organizationId,$inventoryId,$input,$meta){
            // MysqlPropertyCanonicalRuntimeRepository locks this row with FOR UPDATE while the transaction is active.
            // Competing reservations therefore serialize before the active-reservation check.
            $item=$this->inventory($organizationId,$inventoryId);
            if(!in_array($item->status->value,[InventoryStatus::AVAILABLE,InventoryStatus::UNDER_OFFER],true))throw new InvalidArgumentException('Inventory item is not reservable.');
            if($this->repository->findActiveReservation($organizationId,$inventoryId)!==null)throw new InvalidArgumentException('Inventory item already has an active reservation.');
            $reservation=new InventoryReservation($organizationId,$this->id((string)($input['reservation_id']??''),'RSV'),$inventoryId,$this->string($input['reserved_for_reference']??null),new DateTimeImmutable(),$this->date($input['expires_at']??null),null,$this->string($input['reason']??null));
            $reserved=$item->changeStatus(InventoryStatus::from(InventoryStatus::RESERVED));
            $re=InventoryDomainEvents::reserved($reservation,$meta);$se=InventoryDomainEvents::statusChanged($reserved,$item->status,$reserved->status,$meta);
            $this->repository->saveReservation($reservation);$this->repository->saveInventory($reserved,null,$se->id,'reservation:'.$reservation->reservationId);$this->events->publish($re);$this->events->publish($se);$this->compatibility->sync($reserved->organizationId,$reserved->propertyAssetId);
            return ['inventory'=>$reserved->toArray(),'reservation_id'=>$reservation->reservationId];
        });
    }

    public function releaseReservation(string $organizationId,string $inventoryId,?string $actorId=null,?string $correlationId=null): array
    {
        $item=$this->inventory($organizationId,$inventoryId);$active=$this->repository->findActiveReservation($organizationId,$inventoryId);if($active===null)throw new InvalidArgumentException('Active reservation was not found.');$meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($item,$active,$meta){$released=$this->repository->releaseReservation($item->organizationId,$active->reservationId,'now');if($released===null)throw new InvalidArgumentException('Reservation disappeared during release.');
            $this->events->publish(InventoryDomainEvents::released($released,$meta));$next=$item->status->value===InventoryStatus::RESERVED?$item->changeStatus(InventoryStatus::available()):$item;
            if($next->status->value!==$item->status->value){$event=InventoryDomainEvents::statusChanged($next,$item->status,$next->status,$meta);$this->repository->saveInventory($next,null,$event->id,'reservation_released:'.$active->reservationId);$this->events->publish($event);} $this->compatibility->sync($next->organizationId,$next->propertyAssetId);
            return ['inventory'=>$next->toArray(),'reservation_id'=>$released->reservationId];});
    }

    public function createListing(string $organizationId,string $inventoryId,array $input,?string $actorId=null,?string $correlationId=null): array
    {
        $inventory=$this->inventory($organizationId,$inventoryId);$listing=$this->listingFromInput($organizationId,$inventoryId,$input);if($this->repository->findListing($organizationId,$listing->listingId)!==null)throw new InvalidArgumentException('Listing already exists.');$meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($inventory,$listing,$meta){$event=ListingDomainEvents::created($listing,$meta);$this->repository->saveListing($listing);$this->events->publish($event);$this->compatibility->sync($inventory->organizationId,$inventory->propertyAssetId);return$this->listingArray($listing);});
    }

    public function updateListing(string $organizationId,string $listingId,array $input,?string $actorId=null,?string $correlationId=null): array
    {
        $old=$this->listing($organizationId,$listingId);$listing=new Listing($organizationId,$listingId,$old->inventoryId,ListingStatus::from((string)($input['status']??$old->status->value)),(string)($input['title']??$old->title),(string)($input['description']??$old->description),
            array_key_exists('presentation_price_amount',$input)?$this->float($input['presentation_price_amount']):$old->presentationPriceAmount,strtoupper((string)($input['presentation_price_currency']??$old->presentationPriceCurrency)),(string)($input['slug']??$old->slug),(string)($input['visibility']??$old->visibility),
            array_key_exists('seo_title',$input)?$this->string($input['seo_title']):$old->seoTitle,array_key_exists('seo_description',$input)?$this->string($input['seo_description']):$old->seoDescription,isset($input['public_features'])&&is_array($input['public_features'])?$input['public_features']:$old->publicFeatures);
        $inventory=$this->inventory($organizationId,$listing->inventoryId);
        return $this->transactions->transactional(function()use($inventory,$listing){$this->repository->saveListing($listing);$this->compatibility->sync($inventory->organizationId,$inventory->propertyAssetId);return$this->listingArray($listing);});
    }

    public function publishListing(string $organizationId,string $listingId,string $channelCode='estatebook',?string $externalId=null,?string $externalUrl=null,?string $actorId=null,?string $correlationId=null): array
    {
        $listing=$this->listing($organizationId,$listingId);$inventory=$this->inventory($organizationId,$listing->inventoryId);if(!$inventory->status->isMarketable()&&$inventory->status->value!==InventoryStatus::RESERVED)throw new InvalidArgumentException('Listing cannot be published while Inventory is off market or closed.');
        $listing=$listing->changeStatus(ListingStatus::from(ListingStatus::PUBLISHED));$existing=$this->repository->findPublicationForChannel($organizationId,$listingId,$channelCode);
        $publication=new Publication($organizationId,$existing?->publicationId??$this->id('','PUB'),$listingId,$channelCode,PublicationState::from(PublicationState::PUBLISHED),$externalId??$existing?->externalId,$externalUrl??$existing?->externalUrl,new DateTimeImmutable(),null,$existing?->expiresAt,$existing?->lastSyncedAt,$channelCode==='estatebook'?'local':($existing?->syncStatus??'pending'),null);
        $meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($inventory,$listing,$publication,$meta){$event=ListingDomainEvents::published($listing,$publication,$meta);$this->repository->saveListing($listing);$this->repository->savePublication($publication,$event->id);$this->events->publish($event);$this->compatibility->sync($inventory->organizationId,$inventory->propertyAssetId);
            return ['listing'=>$this->listingArray($listing),'publication_id'=>$publication->publicationId,'state'=>$publication->state->value];});
    }

    public function hideListing(string $organizationId,string $listingId,string $channelCode='estatebook',?string $actorId=null,?string $correlationId=null): array
    {
        $listing=$this->listing($organizationId,$listingId);$inventory=$this->inventory($organizationId,$listing->inventoryId);$old=$this->repository->findPublicationForChannel($organizationId,$listingId,$channelCode);if($old===null)throw new InvalidArgumentException('Publication was not found.');
        $hidden=$channelCode==='estatebook'?$listing->changeStatus(ListingStatus::from(ListingStatus::HIDDEN)):$listing;
        $publication=new Publication($old->organizationId,$old->publicationId,$old->listingId,$old->channelCode,PublicationState::from(PublicationState::HIDDEN),$old->externalId,$old->externalUrl,$old->publishedAt,new DateTimeImmutable(),$old->expiresAt,$old->lastSyncedAt,$old->syncStatus,$old->syncError);$meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($inventory,$hidden,$publication,$meta){$event=ListingDomainEvents::hidden($hidden,$publication,$meta);$this->repository->saveListing($hidden);$this->repository->savePublication($publication,$event->id);$this->events->publish($event);$this->compatibility->sync($inventory->organizationId,$inventory->propertyAssetId);
            return ['listing'=>$this->listingArray($hidden),'publication_id'=>$publication->publicationId,'state'=>$publication->state->value];});
    }

    public function createBundle(string $organizationId,array $assetInput,array $inventoryInput,array $listingInput,?string $actorId=null,?string $correlationId=null): array
    {
        $asset=$this->assetFromInput($organizationId,$assetInput);$inventory=$this->inventoryFromInput($organizationId,$asset->assetId,$inventoryInput);$listingInput['listing_id']=$listingInput['listing_id']??$this->id('','LST');$listing=$this->listingFromInput($organizationId,$inventory->inventoryId,$listingInput);$meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($asset,$inventory,$listing,$meta){if($this->repository->findAsset($asset->organizationId,$asset->assetId)!==null)throw new InvalidArgumentException('Property asset already exists.');$ae=PropertyDomainEvents::assetRegistered($asset,$meta);$ie=InventoryDomainEvents::created($inventory,$meta);$le=ListingDomainEvents::created($listing,$meta);
            $this->repository->saveAsset($asset);$this->repository->saveInventory($inventory,$ie->id,$ie->id,'created');$this->repository->saveListing($listing);$this->events->publish($ae);$this->events->publish($ie);$this->events->publish($le);$legacy=$this->compatibility->sync($asset->organizationId,$asset->assetId);
            return ['asset_id'=>$asset->assetId,'inventory_id'=>$inventory->inventoryId,'listing_id'=>$listing->listingId,'legacy_property_id'=>$legacy];});
    }

    public function patchBundleByLegacyId(string $organizationId,int $legacyPropertyId,array $input,?string $actorId=null,?string $correlationId=null): array
    {
        $assetId=$this->repository->assetIdForLegacy($organizationId,$legacyPropertyId);if($assetId===null)throw new InvalidArgumentException('Canonical Property asset was not found for legacy identifier.');$before=$this->asset($organizationId,$assetId);$after=$this->patchAsset($before,$input);$inventory=$this->repository->primaryInventoryForAsset($organizationId,$assetId);$listing=$inventory!==null?$this->repository->primaryListingForInventory($organizationId,$inventory->inventoryId):null;$meta=$this->metadata($actorId,$correlationId);
        return $this->transactions->transactional(function()use($organizationId,$legacyPropertyId,$before,$after,$inventory,$listing,$input,$meta){$this->repository->saveAsset($after);foreach(PropertyDomainEvents::changes($before,$after,$meta)as$e)$this->events->publish($e);$nextInventory=$inventory;
            if($inventory!==null){$nextInventory=new InventoryItem($organizationId,$inventory->inventoryId,$inventory->propertyAssetId,InventoryTransactionType::from((string)($input['deal_type']??$inventory->transactionType->value)),$inventory->status,
                array_key_exists('price_amount',$input)?$this->float($input['price_amount']):$inventory->priceAmount,strtoupper((string)($input['price_currency']??$inventory->priceCurrency)),$this->string($input['price_period']??$inventory->pricePeriod),$inventory->availableFrom,$inventory->availableUntil,
                array_key_exists('agent_id',$input)&&(int)$input['agent_id']>0?'LEGACY:agent:'.(int)$input['agent_id']:$inventory->responsiblePartyReference,$inventory->sourceReference);
                $changed=$nextInventory->priceAmount!==$inventory->priceAmount||$nextInventory->priceCurrency!==$inventory->priceCurrency||$nextInventory->pricePeriod!==$inventory->pricePeriod;$pe=$changed?InventoryDomainEvents::priceChanged($inventory,$nextInventory,$meta):null;$this->repository->saveInventory($nextInventory,$pe?->id);if($pe!==null)$this->events->publish($pe);}
            if($listing!==null){$features=$listing->publicFeatures;foreach(['tour_url','video_url','is_featured']as$f)if(array_key_exists($f,$input))$features[$f]=$input[$f];$nextListing=new Listing($organizationId,$listing->listingId,$listing->inventoryId,$listing->status,(string)($input['title']??$listing->title),(string)($input['description']??$input['short_description']??$listing->description),$nextInventory?->priceAmount??$listing->presentationPriceAmount,$nextInventory?->priceCurrency??$listing->presentationPriceCurrency,(string)($input['slug']??$listing->slug),(string)($input['visibility']??$listing->visibility),array_key_exists('meta_title',$input)?$this->string($input['meta_title']):$listing->seoTitle,array_key_exists('meta_description',$input)?$this->string($input['meta_description']):$listing->seoDescription,$features);$this->repository->saveListing($nextListing);}
            return ['asset_id'=>$after->assetId,'legacy_property_id'=>$this->compatibility->sync($organizationId,$after->assetId)??$legacyPropertyId];});
    }

    public function applyLegacyStatus(string $organizationId,int $legacyPropertyId,string $status,?string $reason=null,?string $actorId=null,?string $correlationId=null): array
    {
        $assetId=$this->repository->assetIdForLegacy($organizationId,$legacyPropertyId);if($assetId===null)throw new InvalidArgumentException('Canonical Property asset was not found.');$inventory=$this->repository->primaryInventoryForAsset($organizationId,$assetId);if($inventory===null)throw new InvalidArgumentException('Canonical Inventory was not found.');$listing=$this->repository->primaryListingForInventory($organizationId,$inventory->inventoryId);$status=strtolower(trim($status));
        if(in_array($status,['published','active'],true)){if($inventory->status->value!==InventoryStatus::AVAILABLE)$this->changeInventoryStatus($organizationId,$inventory->inventoryId,InventoryStatus::AVAILABLE,$reason,$actorId,$correlationId);if($listing===null)throw new InvalidArgumentException('Canonical Listing was not found.');return$this->publishListing($organizationId,$listing->listingId,'estatebook',null,null,$actorId,$correlationId);}
        if($status==='reserved')return$this->changeInventoryStatus($organizationId,$inventory->inventoryId,InventoryStatus::RESERVED,$reason,$actorId,$correlationId);
        if($status==='sold')return$this->changeInventoryStatus($organizationId,$inventory->inventoryId,InventoryStatus::SOLD,$reason,$actorId,$correlationId);
        if($status==='archived'){$r=$this->changeInventoryStatus($organizationId,$inventory->inventoryId,InventoryStatus::WITHDRAWN,$reason,$actorId,$correlationId);if($listing!==null&&$this->repository->findPublicationForChannel($organizationId,$listing->listingId,'estatebook')!==null)$this->hideListing($organizationId,$listing->listingId,'estatebook',$actorId,$correlationId);return$r;}
        if(in_array($status,['draft','submitted','moderation','hidden'],true)){$r=$this->changeInventoryStatus($organizationId,$inventory->inventoryId,InventoryStatus::OFF_MARKET,$reason,$actorId,$correlationId);if($listing!==null&&$this->repository->findPublicationForChannel($organizationId,$listing->listingId,'estatebook')!==null)$this->hideListing($organizationId,$listing->listingId,'estatebook',$actorId,$correlationId);return$r;}
        throw new InvalidArgumentException('Unsupported legacy Property status bridge: '.$status);
    }

    private function asset(string $org,string $id): PropertyAsset { return $this->repository->findAsset($org,$id)??throw new InvalidArgumentException('Property asset was not found in the current organization.'); }
    private function inventory(string $org,string $id): InventoryItem { return $this->repository->findInventory($org,$id)??throw new InvalidArgumentException('Inventory item was not found in the current organization.'); }
    private function listing(string $org,string $id): Listing { return $this->repository->findListing($org,$id)??throw new InvalidArgumentException('Listing was not found in the current organization.'); }

    private function assetFromInput(string $org,array $i): PropertyAsset
    {
        $type=new PropertyType((string)($i['type_code']??'property'),isset($i['type_reference_id'])?(int)$i['type_reference_id']:null);$location=new PropertyLocation(strtoupper((string)($i['country_code']??'UA')),(string)($i['region']??''),(string)($i['city']??''),$this->string($i['district']??null),$this->string($i['address']??null),$this->float($i['latitude']??null),$this->float($i['longitude']??null));
        return new PropertyAsset($org,$this->id((string)($i['asset_id']??''),'PROP'),$type,$location,PropertyLifecycle::from((string)($i['lifecycle']??PropertyLifecycle::UNKNOWN)),$this->float($i['area_total']??$i['total_area']??null),$this->float($i['area_living']??$i['living_area']??null),$this->float($i['land_area']??null),$this->float($i['rooms']??null),$this->int($i['floor']??null),$this->int($i['floors']??null),$this->int($i['built_year']??null),null,isset($i['kind'])&&trim((string)$i['kind'])!==''?PropertyAssetKind::from((string)$i['kind']):null);
    }

    private function patchAsset(PropertyAsset $a,array $i): PropertyAsset
    {
        $type=new PropertyType((string)($i['type_code']??$a->type->code),array_key_exists('type_reference_id',$i)?$this->int($i['type_reference_id']):$a->type->referenceId);$l=$a->location;$location=new PropertyLocation(strtoupper((string)($i['country_code']??$l->countryCode)),(string)($i['region']??$l->region),(string)($i['city']??$l->city),array_key_exists('district',$i)?$this->string($i['district']):$l->district,array_key_exists('address',$i)?$this->string($i['address']):$l->address,array_key_exists('latitude',$i)?$this->float($i['latitude']):$l->latitude,array_key_exists('longitude',$i)?$this->float($i['longitude']):$l->longitude);
        return new PropertyAsset($a->organizationId,$a->assetId,$type,$location,PropertyLifecycle::from((string)($i['lifecycle']??$a->lifecycle->value)),array_key_exists('area_total',$i)||array_key_exists('total_area',$i)?$this->float($i['area_total']??$i['total_area']):$a->totalArea,array_key_exists('area_living',$i)||array_key_exists('living_area',$i)?$this->float($i['area_living']??$i['living_area']):$a->livingArea,array_key_exists('land_area',$i)?$this->float($i['land_area']):$a->landArea,array_key_exists('rooms',$i)?$this->float($i['rooms']):$a->rooms,array_key_exists('floor',$i)?$this->int($i['floor']):$a->floor,array_key_exists('floors',$i)?$this->int($i['floors']):$a->floors,array_key_exists('built_year',$i)?$this->int($i['built_year']):$a->builtYear,$a->persistenceId,isset($i['kind'])&&trim((string)$i['kind'])!==''?PropertyAssetKind::from((string)$i['kind']):$a->kind);
    }

    private function inventoryFromInput(string $org,string $assetId,array $i): InventoryItem
    {
        return new InventoryItem($org,$this->id((string)($i['inventory_id']??''),'INV'),$assetId,InventoryTransactionType::from((string)($i['transaction_type']??'sale')),InventoryStatus::from((string)($i['status']??InventoryStatus::AVAILABLE)),$this->float($i['price_amount']??null),strtoupper((string)($i['price_currency']??'USD')),$this->string($i['price_period']??'total'),$this->date($i['available_from']??null),$this->date($i['available_until']??null),$this->string($i['responsible_party_reference']??null),$this->string($i['source_id']??null));
    }

    private function listingFromInput(string $org,string $inventoryId,array $i): Listing
    {
        $title=trim((string)($i['title']??''));if($title==='')throw new InvalidArgumentException('Listing title is required.');$slug=trim((string)($i['slug']??''))?:$this->slug($title);
        return new Listing($org,$this->id((string)($i['listing_id']??''),'LST'),$inventoryId,ListingStatus::from((string)($i['status']??ListingStatus::DRAFT)),$title,(string)($i['description']??''),$this->float($i['presentation_price_amount']??null),strtoupper((string)($i['presentation_price_currency']??'USD')),$slug,(string)($i['visibility']??'public'),$this->string($i['seo_title']??null),$this->string($i['seo_description']??null),isset($i['public_features'])&&is_array($i['public_features'])?$i['public_features']:[]);
    }

    private function listingArray(Listing $l): array { return ['organization_id'=>$l->organizationId,'listing_id'=>$l->listingId,'inventory_id'=>$l->inventoryId,'status'=>$l->status->value,'title'=>$l->title,'description'=>$l->description,'presentation_price_amount'=>$l->presentationPriceAmount,'presentation_price_currency'=>$l->presentationPriceCurrency,'slug'=>$l->slug,'visibility'=>$l->visibility,'seo_title'=>$l->seoTitle,'seo_description'=>$l->seoDescription,'public_features'=>$l->publicFeatures]; }
    private function metadata(?string $actor,?string $correlation): EventMetadata { $correlation=trim((string)$correlation);return new EventMetadata($correlation!==''?$correlation:bin2hex(random_bytes(12)),null,$actor!==null?'USER':'SYSTEM',$actor??'system'); }
    private function id(string $v,string $prefix): string { $v=trim($v);return$v!==''?$v:$prefix.'-'.strtoupper(bin2hex(random_bytes(10))); }
    private function slug(string $v): string { $v=mb_strtolower(trim($v));$v=preg_replace('/[^\pL\pN]+/u','-',$v)??'';$v=trim($v,'-');return$v!==''?mb_substr($v,0,180):'property-'.strtolower(bin2hex(random_bytes(6))); }
    private function date(mixed $v): ?DateTimeImmutable { return$v===null||$v===''?null:new DateTimeImmutable((string)$v); }
    private function string(mixed $v): ?string { if($v===null)return null;$v=trim((string)$v);return$v===''?null:$v; }
    private function float(mixed $v): ?float { return$v===null||$v===''?null:(float)$v; }
    private function int(mixed $v): ?int { return$v===null||$v===''?null:(int)$v; }
}
