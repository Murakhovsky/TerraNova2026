<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Persistence;

use Domains\ProductManagement\Application\Contract\ProductManagementStoreInterface;
use DomainException;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use PDO;
use PDOException;
use Throwable;

/** All mutation+DomainEvent+existing Kernel Outbox writes share one PDO transaction. */
final readonly class MySqlProductManagementStore implements ProductManagementStoreInterface
{
    public function __construct(private PDO $connection, private EventStoreInterface $events) {}

    public function createProduct(string $organizationId, array $data, string $key, string $actor, string $correlation): array
    {
        return $this->mutate($organizationId, 'product.create', $key, [$data], $actor, $correlation,
            function () use ($organizationId, $data): array {
                $id = bin2hex(random_bytes(16));
                $variant = bin2hex(random_bytes(16));
                $this->execute('INSERT INTO pm_products (organization_id,product_id,product_type,status,name) VALUES (?,?,?,\'draft\',?)',
                    [$organizationId, $id, $data['type'], $data['name']]);
                $this->execute('INSERT INTO pm_variants (organization_id,variant_id,product_id,sku,uom,track_inventory) VALUES (?,?,?,?,?,?)',
                    [$organizationId, $variant, $id, $data['sku'], $data['uom'], $data['track_inventory'] ? 1 : 0]);
                return ['product_id'=>$id, 'variant_id'=>$variant, 'sku'=>$data['sku']];
            }, 'product_management.product.created', 'Product');
    }

    public function createVariant(string $organizationId,string $productId,string $sku,string $uom,bool $track,string $key,string $actor,string $correlation): array
    {
        return $this->mutate($organizationId,'variant.create',$key,[$productId,$sku,$uom,$track],$actor,$correlation,
            function () use ($organizationId,$productId,$sku,$uom,$track): array {
                $product=$this->one('SELECT product_type,status FROM pm_products WHERE organization_id=? AND product_id=? FOR UPDATE',[$organizationId,$productId]);
                if ($product===null||$product['status']==='archived') throw new DomainException('Unknown or archived product.');
                if (in_array($product['product_type'],['service','digital'],true)&&$track) throw new DomainException('Non-stock product cannot track inventory.');
                $id=bin2hex(random_bytes(16));
                $this->execute('INSERT INTO pm_variants (organization_id,variant_id,product_id,sku,uom,track_inventory) VALUES (?,?,?,?,?,?)',
                    [$organizationId,$id,$productId,$sku,$uom,$track?1:0]);
                return ['product_id'=>$productId,'variant_id'=>$id,'sku'=>$sku];
            },'product_management.variant.created','Product');
    }

    public function updateVariant(string $org,string $variantId,array $changes,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'variant.update',$key,[$variantId,$changes,$expectedRevision],$actor,$correlation,
            function()use($org,$variantId,$changes,$expectedRevision):array {
                $variant=$this->one('SELECT v.sku,v.revision,v.status,p.status AS product_status FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE',
                    [$org,$variantId]);
                if ($variant===null||$variant['status']!=='active'||$variant['product_status']==='archived') {
                    throw new DomainException('Variant or parent Product is inactive.');
                }
                if ((int)$variant['revision']!==$expectedRevision) throw new DomainException('Variant revision conflict.');
                if (isset($changes['sku']) && $changes['sku']!==$variant['sku']) {
                    $history=$this->one('SELECT movement_id FROM pm_stock_movements WHERE organization_id=? AND variant_id=? LIMIT 1',
                        [$org,$variantId]);
                    if ($history!==null) throw new DomainException('Cannot change SKU after stock movements.');
                }
                $allowed=['sku','uom','barcode','options_json'];
                $set=[];$values=[];
                foreach($changes as $field=>$value) {
                    if (!in_array($field,$allowed,true)) throw new DomainException('Variant field not editable.');
                    $set[]=$field.'=?';$values[]=$value;
                }
                $values[]=$org;$values[]=$variantId;
                $this->execute('UPDATE pm_variants SET '.implode(',',$set).',revision=revision+1 WHERE organization_id=? AND variant_id=?',$values);
                return ['variant_id'=>$variantId,'revision'=>$expectedRevision+1,'status'=>'active'];
            },'product_management.variant.updated','Variant');
    }

    public function archiveVariant(string $org,string $variantId,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'variant.archive',$key,[$variantId,$expectedRevision],$actor,$correlation,
            function()use($org,$variantId,$expectedRevision):array {
                $variant=$this->one('SELECT revision,status FROM pm_variants WHERE organization_id=? AND variant_id=? FOR UPDATE',
                    [$org,$variantId]);
                if ($variant===null||$variant['status']!=='active') throw new DomainException('Variant absent or archived.');
                if ((int)$variant['revision']!==$expectedRevision) throw new DomainException('Variant revision conflict.');
                $balance=$this->one('SELECT location_id FROM pm_stock_balances WHERE organization_id=? AND variant_id=? AND (on_hand<>0 OR reserved<>0) LIMIT 1 FOR UPDATE',
                    [$org,$variantId]);
                if ($balance!==null) throw new DomainException('Variant cannot be archived with stock or reservations.');
                $this->execute("UPDATE pm_variants SET status='archived',revision=revision+1 WHERE organization_id=? AND variant_id=?",
                    [$org,$variantId]);
                return ['variant_id'=>$variantId,'revision'=>$expectedRevision+1,'status'=>'archived'];
            },'product_management.variant.archived','Variant');
    }

    public function updateProduct(string $organizationId, string $productId, array $changes, int $expectedRevision, string $key, string $actor, string $correlation): array
    {
        return $this->mutate($organizationId,'product.update',$key,[$productId,$changes,$expectedRevision],$actor,$correlation,
            function () use ($organizationId,$productId,$changes,$expectedRevision): array {
                $row=$this->one('SELECT revision,status FROM pm_products WHERE organization_id=? AND product_id=? FOR UPDATE',[$organizationId,$productId]);
                if ($row===null||$row['status']==='archived') throw new DomainException('Product absent or archived.');
                if ((int)$row['revision']!==$expectedRevision) throw new DomainException('Product revision conflict.');
                $allowed=['name','description','brand'];
                $set=[];$values=[];
                foreach ($changes as $field=>$value) {
                    if (!in_array($field,$allowed,true)) throw new DomainException('Unexpected Product field.');
                    $set[]=$field.'=?';$values[]=$value;
                }
                $values[]=$organizationId;$values[]=$productId;
                $this->execute('UPDATE pm_products SET '.implode(',',$set).',revision=revision+1 WHERE organization_id=? AND product_id=?',$values);
                return ['product_id'=>$productId,'revision'=>$expectedRevision+1,'status'=>$row['status']];
            },'product_management.product.updated','Product');
    }

    public function archiveProduct(string $organizationId, string $productId, int $expectedRevision, string $key, string $actor, string $correlation): array
    {
        return $this->mutate($organizationId,'product.archive',$key,[$productId,$expectedRevision],$actor,$correlation,
            function () use ($organizationId,$productId,$expectedRevision): array {
                $row=$this->one('SELECT revision,status FROM pm_products WHERE organization_id=? AND product_id=? FOR UPDATE',[$organizationId,$productId]);
                if ($row===null||$row['status']==='archived') throw new DomainException('Product absent or archived.');
                if ((int)$row['revision']!==$expectedRevision) throw new DomainException('Product revision conflict.');
                $reserved=$this->one('SELECT COUNT(*) AS total FROM pm_stock_balances b JOIN pm_variants v ON v.organization_id=b.organization_id AND v.variant_id=b.variant_id WHERE v.organization_id=? AND v.product_id=? AND (b.on_hand<>0 OR b.reserved<>0)',[$organizationId,$productId]);
                if ($reserved!==null && (int)$reserved['total']>0) throw new DomainException('Product has nonzero stock or reservations.');
                $this->execute("UPDATE pm_products SET status='archived',revision=revision+1 WHERE organization_id=? AND product_id=?",[$organizationId,$productId]);
                return ['product_id'=>$productId,'revision'=>$expectedRevision+1,'status'=>'archived'];
            },'product_management.product.archived','Product');
    }

    public function searchStockMovements(string $organizationId, string $variantId, int $limit, int $offset): array
    {
        $stmt=$this->connection->prepare('SELECT movement_id,variant_id,location_id,quantity_delta,movement_type,source_ref,actor_ref,occurred_at FROM pm_stock_movements WHERE organization_id=:org AND variant_id=:variant ORDER BY occurred_at DESC,movement_id DESC LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$organizationId);
        $stmt->bindValue(':variant',$variantId);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createCategory(string $org,string $name,string $slug,?string $parentId,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'category.create',$key,[$name,$slug,$parentId],$actor,$correlation,
            function() use ($org,$name,$slug,$parentId):array {
                if($parentId!==null) {
                    $parent=$this->one('SELECT category_id FROM pm_categories WHERE organization_id=? AND category_id=?',
                        [$org,$parentId]);
                    if($parent===null)throw new DomainException('Parent category missing in tenant.');
                }
                $id=bin2hex(random_bytes(16));
                $this->execute('INSERT INTO pm_categories (organization_id,category_id,parent_id,name,slug) VALUES (?,?,?,?,?)',
                    [$org,$id,$parentId,$name,$slug]);
                return ['category_id'=>$id,'slug'=>$slug,'parent_id'=>$parentId];
            },'product_management.category.created','Category');
    }

    public function assignCategory(string $org,string $productId,string $categoryId,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'category.assign',$key,[$productId,$categoryId],$actor,$correlation,
            function() use ($org,$productId,$categoryId):array {
                $product=$this->one('SELECT status FROM pm_products WHERE organization_id=? AND product_id=? FOR UPDATE',
                    [$org,$productId]);
                if($product===null||$product['status']==='archived')throw new DomainException('Product missing or archived.');
                if($this->one('SELECT category_id FROM pm_categories WHERE organization_id=? AND category_id=?',
                    [$org,$categoryId])===null)throw new DomainException('Category missing in tenant.');
                $this->execute('INSERT INTO pm_product_categories (organization_id,product_id,category_id) VALUES (?,?,?)',
                    [$org,$productId,$categoryId]);
                return ['product_id'=>$productId,'category_id'=>$categoryId];
            },'product_management.category.assigned','Product');
    }

    public function searchCategories(string $org,int $limit,int $offset):array
    {
        $stmt=$this->connection->prepare('SELECT category_id,parent_id,name,slug FROM pm_categories WHERE organization_id=:org ORDER BY name,category_id LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$org);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsertProductTranslation(string $org,string $productId,string $locale,string $name,?string $description,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'product.translation.upsert',$key,[$productId,$locale,$name,$description],$actor,$correlation,
            function()use($org,$productId,$locale,$name,$description):array{
                $row=$this->one('SELECT status FROM pm_products WHERE organization_id=? AND product_id=? FOR UPDATE',[$org,$productId]);
                if($row===null||$row['status']==='archived')throw new DomainException('Product absent or archived for translation.');
                $this->execute('INSERT INTO pm_product_translations (organization_id,product_id,locale,name,description) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description)',
                    [$org,$productId,$locale,$name,$description]);
                return ['product_id'=>$productId,'locale'=>$locale,'name'=>$name,'description'=>$description];
            },'product_management.translation.upserted','Product');
    }

    /** @param array{file_id:string,storage_key:string,sha256:string,mime_type:string} $file */
    public function attachMedia(string $org,string $productId,?string $variantId,array $file,string $role,int $sortOrder,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'media.attach',$key,
            [$productId,$variantId,$file['file_id'],$file['sha256'],$role,$sortOrder],$actor,$correlation,
            function()use($org,$productId,$variantId,$file,$role,$sortOrder):array{
                $product=$this->one('SELECT status FROM pm_products WHERE organization_id=? AND product_id=? FOR UPDATE',
                    [$org,$productId]);
                if($product===null||$product['status']==='archived')
                    throw new DomainException('Media Product is missing or archived.');
                if($variantId!==null){
                    $variant=$this->one('SELECT product_id,status FROM pm_variants
                        WHERE organization_id=? AND variant_id=? FOR UPDATE',[$org,$variantId]);
                    if($variant===null||$variant['product_id']!==$productId||$variant['status']!=='active')
                        throw new DomainException('Media Variant is not active or belongs to another Product.');
                }
                $existing=$this->one("SELECT media_id FROM pm_media_refs WHERE organization_id=?
                    AND product_id=? AND variant_id <=> ? AND source_file_id=? AND status='active' LIMIT 1",
                    [$org,$productId,$variantId,$file['file_id']]);
                if($existing!==null)throw new DomainException('This verified image is already attached to Product.');
                $mediaId=bin2hex(random_bytes(16));
                $this->execute('INSERT INTO pm_media_refs
                    (organization_id,media_id,product_id,variant_id,storage_ref,source_file_id,checksum,role,sort_order,status)
                    VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$org,$mediaId,$productId,$variantId,$file['storage_key'],$file['file_id'],
                     $file['sha256'],$role,$sortOrder,'active']);
                return ['media_id'=>$mediaId,'product_id'=>$productId,'variant_id'=>$variantId,
                    'file_id'=>$file['file_id'],'role'=>$role,'sort_order'=>$sortOrder,'status'=>'active'];
            },'product_management.media.attached','Product');
    }

    public function detachMedia(string $org,string $mediaId,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'media.detach',$key,[$mediaId],$actor,$correlation,
            function()use($org,$mediaId):array{
                $row=$this->one('SELECT media_id,product_id,status FROM pm_media_refs
                    WHERE organization_id=? AND media_id=? FOR UPDATE',[$org,$mediaId]);
                if($row===null||$row['status']!=='active')
                    throw new DomainException('Active Product Media reference not found.');
                $this->execute("UPDATE pm_media_refs SET status='detached'
                    WHERE organization_id=? AND media_id=? AND status='active'",[$org,$mediaId]);
                return ['media_id'=>$mediaId,'product_id'=>$row['product_id'],'status'=>'detached'];
            },'product_management.media.detached','Product');
    }

    public function searchMedia(string $org,string $productId,int $limit,int $offset):array
    {
        $stmt=$this->connection->prepare("SELECT media_id,product_id,variant_id,source_file_id AS file_id,
            role,sort_order,checksum,status FROM pm_media_refs
            WHERE organization_id=:org AND product_id=:pid AND status='active'
            ORDER BY (role='cover') DESC,sort_order ASC,media_id ASC LIMIT :lim OFFSET :off");
        $stmt->bindValue(':org',$org);
        $stmt->bindValue(':pid',$productId);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProduct(string $organizationId, string $id): ?array
    {
        $row = $this->one('SELECT * FROM pm_products WHERE organization_id=? AND product_id=?', [$organizationId,$id]);
        if ($row === null) return null;
        $row['variants'] = $this->rows('SELECT * FROM pm_variants WHERE organization_id=? AND product_id=? ORDER BY sku', [$organizationId,$id]);
        $row['categories']=$this->rows('SELECT c.category_id,c.name,c.slug,c.parent_id FROM pm_categories c INNER JOIN pm_product_categories pc ON pc.organization_id=c.organization_id AND pc.category_id=c.category_id WHERE pc.organization_id=? AND pc.product_id=? ORDER BY c.name',
            [$organizationId,$id]);
        $row['translations']=$this->rows('SELECT locale,name,description FROM pm_product_translations WHERE organization_id=? AND product_id=? ORDER BY locale',
            [$organizationId,$id]);
        // No internal storage key is returned through Product/Capability reads.
        $row['media']=$this->searchMedia($organizationId,$id,100,0);
        return $row;
    }

    public function searchProducts(string $organizationId, string $query, int $limit, int $offset): array
    {
        $stmt=$this->connection->prepare('SELECT p.product_id,p.name,p.product_type,p.status,v.variant_id,v.sku,v.uom,v.track_inventory FROM pm_products p JOIN pm_variants v ON v.organization_id=p.organization_id AND v.product_id=p.product_id WHERE p.organization_id=:org AND (p.name LIKE :name OR v.sku LIKE :sku) ORDER BY p.name,v.sku LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$organizationId); $stmt->bindValue(':name','%'.$query.'%'); $stmt->bindValue(':sku','%'.$query.'%');
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createLocation(string $organizationId, string $code, string $name, string $key, string $actor, string $correlation): array
    {
        return $this->mutate($organizationId, 'location.create', $key, [$code,$name], $actor, $correlation,
            function () use ($organizationId,$code,$name): array {
                $id=bin2hex(random_bytes(16));
                $this->execute('INSERT INTO pm_locations (organization_id,location_id,code,name) VALUES (?,?,?,?)',
                    [$organizationId,$id,$code,$name]);
                return ['location_id'=>$id,'code'=>$code];
            }, 'product_management.location.created','Location');
    }

    public function postMovement(string $organizationId, string $variantId, string $locationId, string $delta, string $kind, string $source, string $key, string $actor, string $correlation): array
    {
        return $this->mutate($organizationId,'stock.movement',$key,[$variantId,$locationId,$delta,$kind,$source],$actor,$correlation,
            function () use ($organizationId,$variantId,$locationId,$delta,$kind,$source,$key,$actor): array {
                $variant=$this->one('SELECT v.track_inventory,v.status,p.status AS product_status FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=?', [$organizationId,$variantId]);
                $location=$this->one('SELECT status FROM pm_locations WHERE organization_id=? AND location_id=?', [$organizationId,$locationId]);
                if ($variant === null || (int)$variant['track_inventory'] !== 1 || $variant['status'] !== 'active' || $variant['product_status'] === 'archived'
                    || $location === null || $location['status'] !== 'active') {
                    throw new DomainException('Active trackable variant and location required.');
                }
                $this->execute('INSERT IGNORE INTO pm_stock_balances (organization_id,variant_id,location_id) VALUES (?,?,?)',[$organizationId,$variantId,$locationId]);
                $balance=$this->one('SELECT on_hand,reserved FROM pm_stock_balances WHERE organization_id=? AND variant_id=? AND location_id=? FOR UPDATE',[$organizationId,$variantId,$locationId]);
                if ($balance === null) throw new DomainException('Stock row missing.');
                $stmt=$this->connection->prepare('UPDATE pm_stock_balances SET on_hand=on_hand+CAST(:delta AS DECIMAL(20,6)),revision=revision+1 WHERE organization_id=:org AND variant_id=:variant AND location_id=:loc AND on_hand+CAST(:delta_guard AS DECIMAL(20,6))>=reserved');
                $stmt->execute(['delta'=>$delta,'delta_guard'=>$delta,'org'=>$organizationId,'variant'=>$variantId,'loc'=>$locationId]);
                if ($stmt->rowCount()!==1) throw new DomainException('Insufficient available stock; negative balance forbidden.');
                $movement=bin2hex(random_bytes(16));
                $this->execute('INSERT INTO pm_stock_movements (organization_id,movement_id,variant_id,location_id,quantity_delta,movement_type,source_ref,idempotency_key,actor_ref) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$organizationId,$movement,$variantId,$locationId,$delta,$kind,$source,$key,$actor]);
                $stock=$this->stockBalance($organizationId,$variantId,$locationId);
                return ['movement_id'=>$movement,'variant_id'=>$variantId,'location_id'=>$locationId,'delta'=>$delta,'balance'=>$stock];
            }, 'product_management.stock.movement_posted','Stock');
    }

    /** Atomic paired ledger transfer, no additional execution authority. */
    public function transferStock(string $org,string $variantId,string $fromLocation,string $toLocation,string $qty,string $source,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'stock.transfer',$key,
            [$variantId,$fromLocation,$toLocation,$qty,$source],$actor,$correlation,
            function()use($org,$variantId,$fromLocation,$toLocation,$qty,$source,$key,$actor):array {
                $variant=$this->one('SELECT v.track_inventory,v.status,p.status AS product_status FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE',
                    [$org,$variantId]);
                if($variant===null||(int)$variant['track_inventory']!==1||$variant['status']!=='active'||$variant['product_status']==='archived')
                    throw new DomainException('Active trackable Variant required for stock transfer.');
                $locIds=[$fromLocation,$toLocation];
                sort($locIds,SORT_STRING);
                foreach($locIds as $id){
                    $loc=$this->one('SELECT status FROM pm_locations WHERE organization_id=? AND location_id=? FOR UPDATE',[$org,$id]);
                    if($loc===null||$loc['status']!=='active')throw new DomainException('Both tenant warehouse locations must be active.');
                }
                foreach($locIds as $id){
                    $this->execute('INSERT IGNORE INTO pm_stock_balances (organization_id,variant_id,location_id) VALUES (?,?,?)',[$org,$variantId,$id]);
                }
                foreach($locIds as $id){
                    if($this->one('SELECT location_id FROM pm_stock_balances WHERE organization_id=? AND variant_id=? AND location_id=? FOR UPDATE',[$org,$variantId,$id])===null){
                        throw new DomainException('Warehouse stock projection is missing.');
                    }
                }
                $out=$this->connection->prepare('UPDATE pm_stock_balances SET on_hand=on_hand-CAST(:qty AS DECIMAL(20,6)),revision=revision+1 WHERE organization_id=:org AND variant_id=:variant AND location_id=:loc AND on_hand-CAST(:guard AS DECIMAL(20,6))>=reserved');
                $out->execute(['qty'=>$qty,'guard'=>$qty,'org'=>$org,'variant'=>$variantId,'loc'=>$fromLocation]);
                if($out->rowCount()!==1)throw new DomainException('Insufficient available stock for transfer.');
                $in=$this->connection->prepare('UPDATE pm_stock_balances SET on_hand=on_hand+CAST(:qty AS DECIMAL(20,6)),revision=revision+1 WHERE organization_id=:org AND variant_id=:variant AND location_id=:loc');
                $in->execute(['qty'=>$qty,'org'=>$org,'variant'=>$variantId,'loc'=>$toLocation]);
                if($in->rowCount()!==1)throw new DomainException('Destination stock projection update failed.');
                $outId=bin2hex(random_bytes(16));$inId=bin2hex(random_bytes(16));
                $digest=hash('sha256',$org.':'.$key);
                foreach([[$outId,$fromLocation,'-'.$qty,'issue','pm-xfer-out:'.$digest],
                         [$inId,$toLocation,$qty,'receipt','pm-xfer-in:'.$digest]] as $record){
                    $this->execute('INSERT INTO pm_stock_movements (organization_id,movement_id,variant_id,location_id,quantity_delta,movement_type,source_ref,idempotency_key,actor_ref) VALUES (?,?,?,?,?,?,?,?,?)',
                        [$org,$record[0],$variantId,$record[1],$record[2],$record[3],$source,$record[4],$actor]);
                }
                return ['variant_id'=>$variantId,'from_location_id'=>$fromLocation,'to_location_id'=>$toLocation,
                    'quantity'=>$qty,'source_ref'=>$source,'out_movement_id'=>$outId,'in_movement_id'=>$inId,
                    'from_balance'=>$this->stockBalance($org,$variantId,$fromLocation),
                    'to_balance'=>$this->stockBalance($org,$variantId,$toLocation)];
            },'product_management.stock.transferred','Stock');
    }

    public function stockBalance(string $organizationId, string $variantId, string $locationId): array
    {
        $variant=$this->one('SELECT track_inventory FROM pm_variants WHERE organization_id=? AND variant_id=?',[$organizationId,$variantId]);
        if ($variant===null || (int)$variant['track_inventory']!==1) throw new DomainException('Unknown or non-stock variant.');
        $row=$this->one('SELECT on_hand,reserved,(on_hand-reserved) AS available,revision FROM pm_stock_balances WHERE organization_id=? AND variant_id=? AND location_id=?',[$organizationId,$variantId,$locationId]);
        if ($row === null) {
            $loc=$this->one('SELECT location_id FROM pm_locations WHERE organization_id=? AND location_id=?',[$organizationId,$locationId]);
            if ($loc===null) throw new DomainException('Unknown location.');
            return ['variant_id'=>$variantId,'location_id'=>$locationId,'on_hand'=>'0.000000','reserved'=>'0.000000','available'=>'0.000000','revision'=>0];
        }
        return ['variant_id'=>$variantId,'location_id'=>$locationId]+$row;
    }

    /** @param array<mixed> $identity @param callable():array<string,mixed> $callback
     * @return array<string,mixed> */
    private function mutate(string $org,string $operation,string $key,array $identity,string $actor,string $correlation,callable $callback,string $eventType,string $aggregateType): array
    {
        $hash=hash('sha256',json_encode([$operation,$identity],JSON_THROW_ON_ERROR));
        $this->connection->beginTransaction();
        try {
            try {
                $this->execute('INSERT INTO pm_operation_receipts (organization_id,idempotency_key,operation,payload_hash) VALUES (?,?,?,?)',
                    [$org,$key,$operation,$hash]);
            } catch (PDOException $e) {
                if ($e->getCode()!=='23000') throw $e;
                $existing=$this->one('SELECT payload_hash,result_json FROM pm_operation_receipts WHERE organization_id=? AND idempotency_key=?',[$org,$key]);
                if ($existing===null||!hash_equals((string)$existing['payload_hash'],$hash)||$existing['result_json']===null) throw new DomainException('Idempotency conflict or uncommitted result.');
                $this->connection->commit();
                return json_decode((string)$existing['result_json'],true,flags:JSON_THROW_ON_ERROR);
            }
            $result=$callback();
            $id=(string)($result['product_id']??$result['variant_id']??$result['category_id']??$result['location_id']??$result['movement_id']??bin2hex(random_bytes(16)));
            $event=new DomainEvent(bin2hex(random_bytes(16)),$org,$eventType,$aggregateType,$id,
                ['operation'=>$operation,'result'=>$result],new EventMetadata(strlen($correlation)<=40?$correlation:substr(hash('sha256',$correlation),0,40),null,str_starts_with($actor,'shopify:')?'INTEGRATION':'USER',$actor),new \DateTimeImmutable('now',new \DateTimeZone('UTC')));
            $this->events->append($event); // writes cos_events + cos_event_outbox in this same transaction
            $this->execute('UPDATE pm_operation_receipts SET result_json=? WHERE organization_id=? AND idempotency_key=?',
                [json_encode($result,JSON_THROW_ON_ERROR),$org,$key]);
            $this->connection->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $e;
        }
    }

    /** @param list<mixed> $params */
    private function execute(string $sql,array $params): void
    {
        $stmt=$this->connection->prepare($sql);
        $stmt->execute($params);
    }
    /** @param list<mixed> $params @return array<string,mixed>|null */
    private function one(string $sql,array $params): ?array
    {
        $stmt=$this->connection->prepare($sql);$stmt->execute($params);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }
    /** @param list<mixed> $params @return list<array<string,mixed>> */
    private function rows(string $sql,array $params): array
    {
        $stmt=$this->connection->prepare($sql);$stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
