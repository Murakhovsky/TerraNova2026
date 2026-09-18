<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Property\Application\Contract\PropertyCanonicalRuntimeRepositoryInterface;
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
use PDO;

final readonly class MysqlPropertyCanonicalRuntimeRepository implements PropertyCanonicalRuntimeRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function findAsset(string $organizationId, string $assetId): ?PropertyAsset
    {
        $row = $this->one('SELECT a.id,a.organization_id,a.asset_id,a.kind,a.type_code,a.lifecycle,t.id AS type_reference_id,
                n.node_type AS location_type,n.name AS location_name,n.country_code,
                p.node_type AS parent_type,p.name AS parent_name,g.node_type AS grand_type,g.name AS grand_name,
                ad.formatted_address,gp.latitude,gp.longitude,
                rs.total_area AS residential_total_area,rs.living_area,rs.rooms,rs.floor_number,
                ls.land_area,cs.total_area AS commercial_total_area,bs.gross_area,bs.floors,bs.built_year
            FROM tn_property_assets a
            LEFT JOIN tn_property_types t ON t.code=a.type_code
            LEFT JOIN tn_location_nodes n ON n.id=a.location_node_id
            LEFT JOIN tn_location_nodes p ON p.id=n.parent_id
            LEFT JOIN tn_location_nodes g ON g.id=p.parent_id
            LEFT JOIN tn_addresses ad ON ad.id=a.address_id
            LEFT JOIN tn_geo_points gp ON gp.id=a.geo_point_id
            LEFT JOIN tn_property_residential_specs rs ON rs.organization_id=a.organization_id AND rs.asset_id=a.asset_id
            LEFT JOIN tn_property_land_specs ls ON ls.organization_id=a.organization_id AND ls.asset_id=a.asset_id
            LEFT JOIN tn_property_commercial_specs cs ON cs.organization_id=a.organization_id AND cs.asset_id=a.asset_id
            LEFT JOIN tn_property_building_specs bs ON bs.organization_id=a.organization_id AND bs.asset_id=a.asset_id
            WHERE a.organization_id=:organization_id AND a.asset_id=:asset_id LIMIT 1', [
                'organization_id'=>$organizationId,'asset_id'=>$assetId,
            ]);
        if ($row === null) return null;
        [$region,$city,$district] = $this->locationParts($row);
        if ($city === '') $city = 'Unknown';
        return new PropertyAsset(
            (string)$row['organization_id'], (string)$row['asset_id'],
            new PropertyType((string)$row['type_code'], $this->positiveInt($row['type_reference_id'] ?? null)),
            new PropertyLocation(
                strtoupper((string)($row['country_code'] ?? 'UA')) ?: 'UA', $region, $city,
                $district !== '' ? $district : null, $this->nullableString($row['formatted_address'] ?? null),
                $this->nullableFloat($row['latitude'] ?? null), $this->nullableFloat($row['longitude'] ?? null),
            ),
            PropertyLifecycle::from((string)$row['lifecycle']),
            $this->firstFloat($row['residential_total_area'] ?? null,$row['commercial_total_area'] ?? null,$row['gross_area'] ?? null),
            $this->nullableFloat($row['living_area'] ?? null), $this->nullableFloat($row['land_area'] ?? null),
            $this->nullableFloat($row['rooms'] ?? null), $this->nullableInt($row['floor_number'] ?? null),
            $this->nullableInt($row['floors'] ?? null), $this->nullableInt($row['built_year'] ?? null),
            (int)$row['id'], PropertyAssetKind::from((string)$row['kind']),
        );
    }

    public function assetIdForLegacy(string $organizationId, int $legacyPropertyId): ?string
    {
        if ($legacyPropertyId <= 0) return null;
        $row = $this->one('SELECT asset_id FROM tn_property_asset_legacy_links WHERE organization_id=:organization_id AND legacy_property_id=:legacy_property_id LIMIT 1', [
            'organization_id'=>$organizationId,'legacy_property_id'=>$legacyPropertyId,
        ]);
        if ($row !== null && trim((string)$row['asset_id']) !== '') return (string)$row['asset_id'];
        $row = $this->one('SELECT asset_id FROM tn_property_assets WHERE organization_id=:organization_id AND legacy_property_id=:legacy_property_id LIMIT 1', [
            'organization_id'=>$organizationId,'legacy_property_id'=>$legacyPropertyId,
        ]);
        return $row !== null && trim((string)$row['asset_id']) !== '' ? (string)$row['asset_id'] : null;
    }

    public function saveAsset(PropertyAsset $asset): void
    {
        $node = $this->ensureLocation($asset->location);
        $address = $this->ensureAddress($node, $asset->location->address);
        $point = $this->ensurePoint($asset->location->latitude, $asset->location->longitude);
        $this->exec('INSERT INTO tn_property_assets (organization_id,asset_id,kind,type_code,lifecycle,location_node_id,address_id,geo_point_id)
            VALUES (:organization_id,:asset_id,:kind,:type_code,:lifecycle,:location_node_id,:address_id,:geo_point_id)
            ON DUPLICATE KEY UPDATE kind=VALUES(kind),type_code=VALUES(type_code),lifecycle=VALUES(lifecycle),
                location_node_id=VALUES(location_node_id),address_id=VALUES(address_id),geo_point_id=VALUES(geo_point_id),updated_at=NOW()', [
            'organization_id'=>$asset->organizationId,'asset_id'=>$asset->assetId,'kind'=>$asset->kind->value,
            'type_code'=>$asset->type->code,'lifecycle'=>$asset->lifecycle->value,'location_node_id'=>$node,
            'address_id'=>$address,'geo_point_id'=>$point,
        ]);
        $this->saveSpecs($asset);
    }

    public function findInventory(string $organizationId, string $inventoryId): ?InventoryItem
    {
        $sql='SELECT * FROM tn_property_inventory_items WHERE organization_id=:organization_id AND inventory_id=:inventory_id LIMIT 1';
        if($this->connection->inTransaction())$sql.=' FOR UPDATE';
        $row=$this->one($sql,['organization_id'=>$organizationId,'inventory_id'=>$inventoryId]);
        return $row === null ? null : $this->inventory($row);
    }

    public function primaryInventoryForAsset(string $organizationId, string $assetId): ?InventoryItem
    {
        $row=$this->one('SELECT * FROM tn_property_inventory_items WHERE organization_id=:organization_id AND asset_id=:asset_id
            ORDER BY FIELD(status,"available","reserved","under_offer","on_hold","off_market","sold","rented","withdrawn"),updated_at DESC,id DESC LIMIT 1',[
            'organization_id'=>$organizationId,'asset_id'=>$assetId,
        ]);
        return $row === null ? null : $this->inventory($row);
    }

    public function saveInventory(InventoryItem $item, ?string $priceEventId = null, ?string $statusEventId = null, ?string $statusReason = null): void
    {
        $this->exec('INSERT INTO tn_property_inventory_items (organization_id,inventory_id,asset_id,transaction_type,status,price_amount,price_currency,price_period,available_from,available_until,responsible_party_reference,source_id)
            VALUES (:organization_id,:inventory_id,:asset_id,:transaction_type,:status,:price_amount,:price_currency,:price_period,:available_from,:available_until,:responsible_party_reference,:source_id)
            ON DUPLICATE KEY UPDATE asset_id=VALUES(asset_id),transaction_type=VALUES(transaction_type),status=VALUES(status),price_amount=VALUES(price_amount),
                price_currency=VALUES(price_currency),price_period=VALUES(price_period),available_from=VALUES(available_from),available_until=VALUES(available_until),
                responsible_party_reference=VALUES(responsible_party_reference),source_id=VALUES(source_id),updated_at=NOW()', [
            'organization_id'=>$item->organizationId,'inventory_id'=>$item->inventoryId,'asset_id'=>$item->propertyAssetId,
            'transaction_type'=>$item->transactionType->value,'status'=>$item->status->value,'price_amount'=>$item->priceAmount,
            'price_currency'=>$item->priceCurrency,'price_period'=>$item->pricePeriod,
            'available_from'=>$item->availableFrom?->format('Y-m-d H:i:s'),'available_until'=>$item->availableUntil?->format('Y-m-d H:i:s'),
            'responsible_party_reference'=>$item->responsiblePartyReference,'source_id'=>$item->sourceReference,
        ]);
        if ($priceEventId !== null) $this->exec('INSERT INTO tn_property_inventory_price_history (organization_id,inventory_id,price_amount,price_currency,price_period,event_id)
            VALUES (:organization_id,:inventory_id,:price_amount,:price_currency,:price_period,:event_id)',[
            'organization_id'=>$item->organizationId,'inventory_id'=>$item->inventoryId,'price_amount'=>$item->priceAmount,
            'price_currency'=>$item->priceCurrency,'price_period'=>$item->pricePeriod,'event_id'=>$priceEventId,
        ]);
        if ($statusEventId !== null) $this->exec('INSERT INTO tn_property_inventory_status_history (organization_id,inventory_id,status,reason,event_id)
            VALUES (:organization_id,:inventory_id,:status,:reason,:event_id)',[
            'organization_id'=>$item->organizationId,'inventory_id'=>$item->inventoryId,'status'=>$item->status->value,
            'reason'=>$statusReason,'event_id'=>$statusEventId,
        ]);
    }

    public function findActiveReservation(string $organizationId, string $inventoryId): ?InventoryReservation
    {
        $row=$this->one('SELECT * FROM tn_property_inventory_reservations WHERE organization_id=:organization_id AND inventory_id=:inventory_id
            AND released_at IS NULL AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY reserved_at DESC,id DESC LIMIT 1',[
            'organization_id'=>$organizationId,'inventory_id'=>$inventoryId,
        ]);
        return $row === null ? null : $this->reservation($row);
    }

    public function saveReservation(InventoryReservation $reservation): void
    {
        $this->exec('INSERT INTO tn_property_inventory_reservations (organization_id,reservation_id,inventory_id,reserved_for_reference,reserved_at,expires_at,released_at,reason)
            VALUES (:organization_id,:reservation_id,:inventory_id,:reserved_for_reference,:reserved_at,:expires_at,:released_at,:reason)',[
            'organization_id'=>$reservation->organizationId,'reservation_id'=>$reservation->reservationId,'inventory_id'=>$reservation->inventoryId,
            'reserved_for_reference'=>$reservation->reservedForReference,'reserved_at'=>$reservation->reservedAt->format('Y-m-d H:i:s'),
            'expires_at'=>$reservation->expiresAt?->format('Y-m-d H:i:s'),'released_at'=>$reservation->releasedAt?->format('Y-m-d H:i:s'),'reason'=>$reservation->reason,
        ]);
    }

    public function releaseReservation(string $organizationId, string $reservationId, string $releasedAt): ?InventoryReservation
    {
        $released = new DateTimeImmutable($releasedAt);
        $this->exec('UPDATE tn_property_inventory_reservations SET released_at=:released_at WHERE organization_id=:organization_id AND reservation_id=:reservation_id AND released_at IS NULL LIMIT 1',[
            'released_at'=>$released->format('Y-m-d H:i:s'),'organization_id'=>$organizationId,'reservation_id'=>$reservationId,
        ]);
        $row=$this->one('SELECT * FROM tn_property_inventory_reservations WHERE organization_id=:organization_id AND reservation_id=:reservation_id LIMIT 1',[
            'organization_id'=>$organizationId,'reservation_id'=>$reservationId,
        ]);
        return $row === null ? null : $this->reservation($row);
    }

    public function findListing(string $organizationId, string $listingId): ?Listing
    {
        $row=$this->one('SELECT * FROM tn_property_listings WHERE organization_id=:organization_id AND listing_id=:listing_id LIMIT 1',['organization_id'=>$organizationId,'listing_id'=>$listingId]);
        return $row === null ? null : $this->listing($row);
    }

    public function primaryListingForInventory(string $organizationId, string $inventoryId): ?Listing
    {
        $row=$this->one('SELECT * FROM tn_property_listings WHERE organization_id=:organization_id AND inventory_id=:inventory_id
            ORDER BY FIELD(status,"published","ready","draft","hidden","expired","archived"),updated_at DESC,id DESC LIMIT 1',[
            'organization_id'=>$organizationId,'inventory_id'=>$inventoryId,
        ]);
        return $row === null ? null : $this->listing($row);
    }

    public function saveListing(Listing $listing): void
    {
        $this->exec('INSERT INTO tn_property_listings (organization_id,listing_id,inventory_id,status,title,description,presentation_price_amount,presentation_price_currency,slug,visibility,seo_title,seo_description,public_features_json)
            VALUES (:organization_id,:listing_id,:inventory_id,:status,:title,:description,:presentation_price_amount,:presentation_price_currency,:slug,:visibility,:seo_title,:seo_description,:public_features_json)
            ON DUPLICATE KEY UPDATE inventory_id=VALUES(inventory_id),status=VALUES(status),title=VALUES(title),description=VALUES(description),
                presentation_price_amount=VALUES(presentation_price_amount),presentation_price_currency=VALUES(presentation_price_currency),slug=VALUES(slug),
                visibility=VALUES(visibility),seo_title=VALUES(seo_title),seo_description=VALUES(seo_description),public_features_json=VALUES(public_features_json),updated_at=NOW()',[
            'organization_id'=>$listing->organizationId,'listing_id'=>$listing->listingId,'inventory_id'=>$listing->inventoryId,
            'status'=>$listing->status->value,'title'=>$listing->title,'description'=>$listing->description,
            'presentation_price_amount'=>$listing->presentationPriceAmount,'presentation_price_currency'=>$listing->presentationPriceCurrency,
            'slug'=>$listing->slug,'visibility'=>$listing->visibility,'seo_title'=>$listing->seoTitle,'seo_description'=>$listing->seoDescription,
            'public_features_json'=>json_encode($listing->publicFeatures,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function findPublication(string $organizationId, string $publicationId): ?Publication
    {
        $row=$this->one('SELECT * FROM tn_property_publications WHERE organization_id=:organization_id AND publication_id=:publication_id LIMIT 1',['organization_id'=>$organizationId,'publication_id'=>$publicationId]);
        return $row === null ? null : $this->publication($row);
    }

    public function findPublicationForChannel(string $organizationId, string $listingId, string $channelCode): ?Publication
    {
        $row=$this->one('SELECT * FROM tn_property_publications WHERE organization_id=:organization_id AND listing_id=:listing_id AND channel_code=:channel_code LIMIT 1',[
            'organization_id'=>$organizationId,'listing_id'=>$listingId,'channel_code'=>$channelCode,
        ]);
        return $row === null ? null : $this->publication($row);
    }

    public function savePublication(Publication $publication, ?string $eventId = null): void
    {
        $this->exec('INSERT INTO tn_property_publications (organization_id,publication_id,listing_id,channel_code,state,external_id,external_url,published_at,hidden_at,expires_at,last_synced_at,sync_status,sync_error)
            VALUES (:organization_id,:publication_id,:listing_id,:channel_code,:state,:external_id,:external_url,:published_at,:hidden_at,:expires_at,:last_synced_at,:sync_status,:sync_error)
            ON DUPLICATE KEY UPDATE state=VALUES(state),external_id=VALUES(external_id),external_url=VALUES(external_url),published_at=VALUES(published_at),
                hidden_at=VALUES(hidden_at),expires_at=VALUES(expires_at),last_synced_at=VALUES(last_synced_at),sync_status=VALUES(sync_status),sync_error=VALUES(sync_error),updated_at=NOW()',[
            'organization_id'=>$publication->organizationId,'publication_id'=>$publication->publicationId,'listing_id'=>$publication->listingId,
            'channel_code'=>$publication->channelCode,'state'=>$publication->state->value,'external_id'=>$publication->externalId,'external_url'=>$publication->externalUrl,
            'published_at'=>$publication->publishedAt?->format('Y-m-d H:i:s'),'hidden_at'=>$publication->hiddenAt?->format('Y-m-d H:i:s'),
            'expires_at'=>$publication->expiresAt?->format('Y-m-d H:i:s'),'last_synced_at'=>$publication->lastSyncedAt?->format('Y-m-d H:i:s'),
            'sync_status'=>$publication->syncStatus,'sync_error'=>$publication->syncError,
        ]);
        if ($eventId !== null) $this->exec('INSERT INTO tn_property_listing_publication_history (organization_id,publication_id,state,event_id)
            VALUES (:organization_id,:publication_id,:state,:event_id)',[
            'organization_id'=>$publication->organizationId,'publication_id'=>$publication->publicationId,'state'=>$publication->state->value,'event_id'=>$eventId,
        ]);
    }

    private function inventory(array $r): InventoryItem
    {
        return new InventoryItem((string)$r['organization_id'],(string)$r['inventory_id'],(string)$r['asset_id'],
            InventoryTransactionType::from((string)$r['transaction_type']),InventoryStatus::from((string)$r['status']),
            $this->nullableFloat($r['price_amount'] ?? null),(string)$r['price_currency'],$this->nullableString($r['price_period'] ?? null),
            $this->date($r['available_from'] ?? null),$this->date($r['available_until'] ?? null),
            $this->nullableString($r['responsible_party_reference'] ?? null),$this->nullableString($r['source_id'] ?? null));
    }

    private function reservation(array $r): InventoryReservation
    {
        return new InventoryReservation((string)$r['organization_id'],(string)$r['reservation_id'],(string)$r['inventory_id'],
            $this->nullableString($r['reserved_for_reference'] ?? null),new DateTimeImmutable((string)$r['reserved_at']),
            $this->date($r['expires_at'] ?? null),$this->date($r['released_at'] ?? null),$this->nullableString($r['reason'] ?? null));
    }

    private function listing(array $r): Listing
    {
        $features=json_decode((string)($r['public_features_json'] ?? '{}'),true);
        return new Listing((string)$r['organization_id'],(string)$r['listing_id'],(string)$r['inventory_id'],ListingStatus::from((string)$r['status']),
            (string)$r['title'],(string)$r['description'],$this->nullableFloat($r['presentation_price_amount'] ?? null),(string)$r['presentation_price_currency'],
            (string)$r['slug'],(string)$r['visibility'],$this->nullableString($r['seo_title'] ?? null),$this->nullableString($r['seo_description'] ?? null),is_array($features)?$features:[]);
    }

    private function publication(array $r): Publication
    {
        return new Publication((string)$r['organization_id'],(string)$r['publication_id'],(string)$r['listing_id'],(string)$r['channel_code'],PublicationState::from((string)$r['state']),
            $this->nullableString($r['external_id'] ?? null),$this->nullableString($r['external_url'] ?? null),$this->date($r['published_at'] ?? null),
            $this->date($r['hidden_at'] ?? null),$this->date($r['expires_at'] ?? null),$this->date($r['last_synced_at'] ?? null),(string)($r['sync_status'] ?? 'pending'),$this->nullableString($r['sync_error'] ?? null));
    }

    private function saveSpecs(PropertyAsset $a): void
    {
        if (in_array($a->kind->value,[PropertyAssetKind::UNIT,PropertyAssetKind::HOUSE],true)) $this->exec('INSERT INTO tn_property_residential_specs (organization_id,asset_id,total_area,living_area,rooms,floor_number)
            VALUES (:organization_id,:asset_id,:total_area,:living_area,:rooms,:floor_number)
            ON DUPLICATE KEY UPDATE total_area=VALUES(total_area),living_area=VALUES(living_area),rooms=VALUES(rooms),floor_number=VALUES(floor_number)',[
            'organization_id'=>$a->organizationId,'asset_id'=>$a->assetId,'total_area'=>$a->totalArea,'living_area'=>$a->livingArea,'rooms'=>$a->rooms,'floor_number'=>$a->floor,
        ]);
        if ($a->kind->value===PropertyAssetKind::LAND_PLOT) $this->exec('INSERT INTO tn_property_land_specs (organization_id,asset_id,land_area) VALUES (:organization_id,:asset_id,:land_area)
            ON DUPLICATE KEY UPDATE land_area=VALUES(land_area)',['organization_id'=>$a->organizationId,'asset_id'=>$a->assetId,'land_area'=>$a->landArea]);
        if ($a->type->code==='commercial') $this->exec('INSERT INTO tn_property_commercial_specs (organization_id,asset_id,total_area) VALUES (:organization_id,:asset_id,:total_area)
            ON DUPLICATE KEY UPDATE total_area=VALUES(total_area)',['organization_id'=>$a->organizationId,'asset_id'=>$a->assetId,'total_area'=>$a->totalArea]);
        if (in_array($a->kind->value,[PropertyAssetKind::DEVELOPMENT,PropertyAssetKind::BUILDING,PropertyAssetKind::HOUSE],true)) $this->exec('INSERT INTO tn_property_building_specs (organization_id,asset_id,gross_area,floors,built_year)
            VALUES (:organization_id,:asset_id,:gross_area,:floors,:built_year) ON DUPLICATE KEY UPDATE gross_area=VALUES(gross_area),floors=VALUES(floors),built_year=VALUES(built_year)',[
            'organization_id'=>$a->organizationId,'asset_id'=>$a->assetId,'gross_area'=>$a->totalArea,'floors'=>$a->floors,'built_year'=>$a->builtYear,
        ]);
    }

    private function ensureLocation(PropertyLocation $l): int
    {
        $parent=null; $countryKey='country:'.strtolower($l->countryCode);
        $parent=$this->ensureNode($parent,'country',$countryKey,$l->countryCode,$l->countryCode);
        if (trim($l->region)!=='') $parent=$this->ensureNode($parent,'region',$countryKey.':region:'.$this->key($l->region),$l->region,$l->countryCode);
        $parent=$this->ensureNode($parent,'city',$countryKey.':city:'.$this->key($l->region.':'.$l->city),$l->city,$l->countryCode);
        if ($l->district!==null && trim($l->district)!=='') $parent=$this->ensureNode($parent,'district',$countryKey.':district:'.$this->key($l->region.':'.$l->city.':'.$l->district),$l->district,$l->countryCode);
        return $parent;
    }

    private function ensureNode(?int $parent,string $type,string $key,string $name,string $country): int
    {
        $row=$this->one('SELECT id FROM tn_location_nodes WHERE canonical_key=:key LIMIT 1',['key'=>$key]); if($row!==null)return(int)$row['id'];
        $this->exec('INSERT INTO tn_location_nodes (node_id,parent_id,node_type,canonical_key,name,country_code) VALUES (:node_id,:parent_id,:node_type,:canonical_key,:name,:country_code)',[
            'node_id'=>'LOC-'.strtoupper(substr(sha1($key),0,16)),'parent_id'=>$parent,'node_type'=>$type,'canonical_key'=>$key,'name'=>mb_substr(trim($name),0,160),'country_code'=>$country,
        ]); return (int)$this->connection->lastInsertId();
    }

    private function ensureAddress(int $node,?string $address): ?int
    {
        $address=trim((string)$address); if($address==='')return null;
        $row=$this->one('SELECT id FROM tn_addresses WHERE locality_node_id=:node AND formatted_address=:address LIMIT 1',['node'=>$node,'address'=>$address]); if($row!==null)return(int)$row['id'];
        $this->exec('INSERT INTO tn_addresses (locality_node_id,formatted_address) VALUES (:node,:address)',['node'=>$node,'address'=>mb_substr($address,0,255)]); return (int)$this->connection->lastInsertId();
    }

    private function ensurePoint(?float $lat,?float $lng): ?int
    {
        if($lat===null||$lng===null)return null;
        $row=$this->one('SELECT id FROM tn_geo_points WHERE latitude=:lat AND longitude=:lng LIMIT 1',['lat'=>$lat,'lng'=>$lng]); if($row!==null)return(int)$row['id'];
        $this->exec('INSERT INTO tn_geo_points (latitude,longitude) VALUES (:lat,:lng)',['lat'=>$lat,'lng'=>$lng]); return (int)$this->connection->lastInsertId();
    }

    private function locationParts(array $r): array
    {
        $region='';$city='';$district='';
        foreach ([[$r['location_type']??'', $r['location_name']??''],[$r['parent_type']??'', $r['parent_name']??''],[$r['grand_type']??'', $r['grand_name']??'']] as [$type,$name]) {
            if($type==='region')$region=(string)$name; if($type==='city')$city=(string)$name; if($type==='district')$district=(string)$name;
        }
        return [$region,$city,$district];
    }

    private function one(string $sql,array $params): ?array { $s=$this->connection->prepare($sql);$s->execute($params);$r=$s->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r; }
    private function exec(string $sql,array $params): void { $s=$this->connection->prepare($sql);$s->execute($params); }
    private function date(mixed $v): ?DateTimeImmutable { return $v===null||$v===''?null:new DateTimeImmutable((string)$v); }
    private function nullableString(mixed $v): ?string { if($v===null)return null;$v=trim((string)$v);return $v===''?null:$v; }
    private function nullableFloat(mixed $v): ?float { return $v===null||$v===''?null:(float)$v; }
    private function nullableInt(mixed $v): ?int { return $v===null||$v===''?null:(int)$v; }
    private function positiveInt(mixed $v): ?int { $v=$this->nullableInt($v);return $v!==null&&$v>0?$v:null; }
    private function firstFloat(mixed ...$values): ?float { foreach($values as $v)if($v!==null&&$v!=='')return(float)$v;return null; }
    private function key(string $v): string { $v=mb_strtolower(trim($v));$v=preg_replace('/[^\pL\pN]+/u','-',$v)??'';return trim($v,'-')?:substr(sha1($v),0,12); }
}
