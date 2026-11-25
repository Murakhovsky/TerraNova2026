<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Persistence;

use Domains\ProductManagement\Application\Contract\ProductManagementStoreInterface;
use Domains\ProductManagement\Domain\AttributeRules;
use DomainException;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use PDO;
use PDOException;
use Throwable;

/** All mutation+DomainEvent+existing Kernel Outbox writes share one PDO transaction. */
final readonly class MySqlProductManagementStore implements ProductManagementStoreInterface, \Domains\ProductManagement\Application\Contract\OrderInventoryPort, \Domains\ProductManagement\Application\Contract\ProductAssemblyTransactionPort
{
    public function __construct(private PDO $connection, private EventStoreInterface $events) {}

    public function assemble(string $org,array $input,string $key,string $actor,string $trace,callable $apply):array
    {
        return $this->mutate($org,'product.assemble',$key,[$input],$actor,$trace,$apply,
            'product_management.product.assembled','Product');
    }

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
                $this->assertShopifyLocalOwnership($organizationId,'product',$productId);
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
                $variant=$this->one('SELECT v.sku,v.product_id,v.revision,v.status,p.status AS product_status FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE',
                    [$org,$variantId]);
                if ($variant===null||$variant['status']!=='active'||$variant['product_status']==='archived') {
                    throw new DomainException('Variant or parent Product is inactive.');
                }
                $this->assertShopifyLocalOwnership($org,'variant',$variantId);
                if ((int)$variant['revision']!==$expectedRevision) throw new DomainException('Variant revision conflict.');
                if (isset($changes['sku']) && $changes['sku']!==$variant['sku']) {
                    $history=$this->one('SELECT movement_id FROM pm_stock_movements WHERE organization_id=? AND variant_id=? LIMIT 1 FOR UPDATE',
                        [$org,$variantId]);
                    if ($history!==null) throw new DomainException('Cannot change SKU after stock movements.');
                }
                if(array_key_exists('options_json',$changes)&&$this->one('SELECT variant_id FROM pm_variant_matrix_identities WHERE organization_id=? AND product_id=? LIMIT 1',[$org,$variant['product_id']])!==null)
                    throw new DomainException('Managed matrix options are immutable; create a new combination.');
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
                $variant=$this->one('SELECT v.revision,v.status,p.status AS product_status FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE',
                    [$org,$variantId]);
                if ($variant===null||$variant['status']!=='active'||$variant['product_status']==='archived') throw new DomainException('Variant or parent Product absent or archived.');
                $this->assertShopifyLocalOwnership($org,'variant',$variantId);
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
                $this->assertShopifyLocalOwnership($organizationId,'product',$productId);
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
                $this->assertShopifyLocalOwnership($organizationId,'product',$productId);
                if ((int)$row['revision']!==$expectedRevision) throw new DomainException('Product revision conflict.');
                $reserved=$this->one('SELECT b.location_id FROM pm_stock_balances b JOIN pm_variants v ON v.organization_id=b.organization_id AND v.variant_id=b.variant_id WHERE v.organization_id=? AND v.product_id=? AND (b.on_hand<>0 OR b.reserved<>0) LIMIT 1 FOR UPDATE',[$organizationId,$productId]);
                if ($reserved!==null) throw new DomainException('Product has nonzero stock or reservations.');
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
                if($this->one('SELECT category_id FROM pm_categories WHERE organization_id=? AND category_id=? FOR UPDATE',
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
                // At most one active cover within a Product or a specific
                // Variant context. Product row lock serializes rival attaches.
                if($role==='cover'){
                    $cover=$this->one("SELECT media_id FROM pm_media_refs
                        WHERE organization_id=? AND product_id=? AND variant_id <=> ?
                          AND role='cover' AND status='active' LIMIT 1",
                        [$org,$productId,$variantId]);
                    if($cover!==null)throw new DomainException('Product or Variant already has an active cover.');
                }
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

    /** @return list<array<string,mixed>> */
    public function searchLocations(string $organizationId,string $q,int $limit,int $offset):array
    {
        $stmt=$this->connection->prepare('SELECT location_id,code,name,status FROM pm_locations
            WHERE organization_id=:org AND (code LIKE :code OR name LIKE :name)
            ORDER BY code,location_id LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$organizationId);
        $stmt->bindValue(':code','%'.$q.'%');
        $stmt->bindValue(':name','%'.$q.'%');
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
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
                $variant=$this->one('SELECT v.track_inventory,v.status,p.status AS product_status FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE', [$organizationId,$variantId]);
                $location=$this->one('SELECT status FROM pm_locations WHERE organization_id=? AND location_id=?', [$organizationId,$locationId]);
                if ($variant === null || (int)$variant['track_inventory'] !== 1 || $variant['status'] !== 'active' || $variant['product_status'] === 'archived'
                    || $location === null || $location['status'] !== 'active') {
                    throw new DomainException('Active trackable variant and location required.');
                }
                $this->assertShopifyLocalOwnership($organizationId,'variant',$variantId);
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
                $this->assertShopifyLocalOwnership($org,'variant',$variantId);
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

    public function reserveStock(string $org,string $variantId,string $locationId,string $qty,string $source,string $key,string $actor,string $correlation):array
    {
        if(!preg_match('#^manual:[A-Za-z0-9._:/-]{1,150}$#D',$source))throw new DomainException('Manual reservation source required.');
        return $this->reserveStockInternal($org,$variantId,$locationId,$qty,$source,$key,$actor,$correlation);
    }

    private function reserveStockInternal(string $org,string $variantId,string $locationId,string $qty,string $source,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'stock.reserve',$key,[$variantId,$locationId,$qty,$source],$actor,$correlation,
            function()use($org,$variantId,$locationId,$qty,$source,$actor):array {
                $variant=$this->one('SELECT v.track_inventory,v.status,p.status AS product_status
                    FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id
                    WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE',[$org,$variantId]);
                if($variant===null||(int)$variant['track_inventory']!==1||$variant['status']!=='active'||$variant['product_status']==='archived') {
                    throw new DomainException('Active trackable Variant is required for reservation.');
                }
                $this->assertShopifyLocalOwnership($org,'variant',$variantId);
                $loc=$this->one('SELECT status FROM pm_locations WHERE organization_id=? AND location_id=? FOR UPDATE',[$org,$locationId]);
                if($loc===null||$loc['status']!=='active')throw new DomainException('Active tenant stock location is required.');
                $this->execute('INSERT IGNORE INTO pm_stock_balances (organization_id,variant_id,location_id) VALUES (?,?,?)',
                    [$org,$variantId,$locationId]);
                if($this->one('SELECT reserved FROM pm_stock_balances WHERE organization_id=? AND variant_id=? AND location_id=? FOR UPDATE',
                    [$org,$variantId,$locationId])===null)throw new DomainException('Stock projection missing.');
                $stmt=$this->connection->prepare('UPDATE pm_stock_balances
                    SET reserved=reserved+CAST(:qty AS DECIMAL(20,6)),revision=revision+1
                    WHERE organization_id=:org AND variant_id=:variant AND location_id=:loc
                    AND reserved+CAST(:guard AS DECIMAL(20,6))<=on_hand');
                $stmt->execute(['qty'=>$qty,'guard'=>$qty,'org'=>$org,'variant'=>$variantId,'loc'=>$locationId]);
                if($stmt->rowCount()!==1)throw new DomainException('Insufficient available stock for reservation.');
                $id=bin2hex(random_bytes(16));
                $this->execute('INSERT INTO pm_stock_reservations
                    (organization_id,reservation_id,variant_id,location_id,source_ref,quantity,status,actor_ref)
                    VALUES (?,?,?,?,?,?,?,?)',[$org,$id,$variantId,$locationId,$source,$qty,'active',$actor]);
                return ['reservation_id'=>$id,'variant_id'=>$variantId,'location_id'=>$locationId,
                    'quantity'=>$qty,'source_ref'=>$source,'status'=>'active',
                    'balance'=>$this->stockBalance($org,$variantId,$locationId)];
            },'product_management.stock.reserved','StockReservation');
    }

    /** Idempotent full release: never changes physical on_hand or creates a movement. */
    public function releaseStock(string $org,string $reservationId,string $reason,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'stock.release',$key,[$reservationId,$reason],$actor,$correlation,
            function()use($org,$reservationId,$reason):array {
                $row=$this->one('SELECT variant_id,location_id,source_ref,quantity,status FROM pm_stock_reservations
                    WHERE organization_id=? AND reservation_id=? FOR UPDATE',[$org,$reservationId]);
                if($row===null||$row['status']!=='active'||!str_starts_with($row['source_ref'],'manual:'))throw new DomainException('Active manual reservation required; order-owned reservations are settled only through the Order port.');
                $variantId=(string)$row['variant_id'];$locationId=(string)$row['location_id'];
                $qty=(string)$row['quantity'];
                $this->assertShopifyLocalOwnership($org,'variant',$variantId);
                if($this->one('SELECT reserved FROM pm_stock_balances WHERE organization_id=? AND variant_id=? AND location_id=? FOR UPDATE',
                    [$org,$variantId,$locationId])===null)throw new DomainException('Reservation balance missing.');
                $stmt=$this->connection->prepare('UPDATE pm_stock_balances
                    SET reserved=reserved-CAST(:qty AS DECIMAL(20,6)),revision=revision+1
                    WHERE organization_id=:org AND variant_id=:variant AND location_id=:loc
                    AND reserved>=CAST(:guard AS DECIMAL(20,6))');
                $stmt->execute(['qty'=>$qty,'guard'=>$qty,'org'=>$org,'variant'=>$variantId,'loc'=>$locationId]);
                if($stmt->rowCount()!==1)throw new DomainException('Reservation balance inconsistency.');
                $this->execute("UPDATE pm_stock_reservations SET status='released',release_reason=?,released_at=UTC_TIMESTAMP(6),
                    revision=revision+1 WHERE organization_id=? AND reservation_id=? AND status='active'",
                    [$reason,$org,$reservationId]);
                return ['reservation_id'=>$reservationId,'variant_id'=>$variantId,'location_id'=>$locationId,
                    'quantity'=>$qty,'source_ref'=>(string)$row['source_ref'],'status'=>'released','reason'=>$reason,
                    'balance'=>$this->stockBalance($org,$variantId,$locationId)];
            },'product_management.stock.released','StockReservation');
    }

    public function searchReservations(string $org,string $variantId,string $locationId,?string $reservationId,int $limit,int $offset):array
    {
        $sql='SELECT reservation_id,variant_id,location_id,source_ref,quantity,status,release_reason,revision,created_at,released_at
            FROM pm_stock_reservations WHERE organization_id=:org AND variant_id=:variant AND location_id=:loc';
        if($reservationId!==null)$sql.=' AND reservation_id=:id';
        $stmt=$this->connection->prepare($sql.' ORDER BY created_at DESC,reservation_id DESC LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$org);$stmt->bindValue(':variant',$variantId);$stmt->bindValue(':loc',$locationId);
        if($reservationId!==null)$stmt->bindValue(':id',$reservationId);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);$stmt->execute();
        return array_map(static function(array $row):array{$row['revision']=(int)$row['revision'];return $row;},$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Internal order-owned reservation; no manual API can create or release this namespace. */
    public function reserveForOrder(string $org,string $product,string $variant,string $sku,?string $location,int $quantity,string $source,string $key,string $actor,string $correlation):array
    {
        $this->orderAdmission($org,$source,$quantity,$key,$actor,$correlation);
        foreach([$product,$variant] as $id)if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid canonical order product reference.');
        $row=$this->one('SELECT v.sku,v.track_inventory,v.status,p.status AS product_status,p.product_type FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=? AND v.product_id=? FOR UPDATE',[$org,$variant,$product]);
        if($row===null||$row['sku']!==$sku||$row['status']!=='active'||$row['product_status']!=='active')throw new DomainException('Order product unavailable.');
        if((int)$row['track_inventory']===0){
            if($location!==null||!in_array($row['product_type'],['service','digital'],true))throw new DomainException('Untracked physical goods require inventory ownership review.');
            return ['stock_mode'=>'nonstock','reservation_id'=>null,'location_id'=>null];
        }
        if($location===null||!preg_match('/^[a-f0-9]{32}$/D',$location))throw new DomainException('Tracked order line requires a warehouse.');
        return $this->mutate($org,'stock.order.reserve',$key,[$product,$variant,$sku,$location,$quantity,$source],$actor,$correlation,
            function()use($org,$variant,$location,$quantity,$source,$key,$actor,$correlation):array{
                $r=$this->reserveStockInternal($org,$variant,$location,(string)$quantity,$source,'order-reserve:'.hash('sha256',$key),$actor,$correlation);
                $this->orderOperation($org,$r['reservation_id'],'reserve',$quantity,null,$source,$key,$actor);
                return ['stock_mode'=>'local','reservation_id'=>$r['reservation_id'],'location_id'=>$location,'balance'=>$r['balance']];
            },'product_management.stock.order_reserved','StockReservation');
    }

    public function settleOrderReservation(string $org,string $reservation,string $source,string $operation,int $quantity,string $key,string $actor,string $correlation):array
    {
        $this->orderAdmission($org,$source,$quantity,$key,$actor,$correlation);
        if(!preg_match('/^[a-f0-9]{32}$/D',$reservation)||!in_array($operation,['commit','release','return'],true))throw new DomainException('Invalid order stock settlement.');
        return $this->mutate($org,'stock.order.'.$operation,$key,[$reservation,$source,$operation,$quantity],$actor,$correlation,
            function()use($org,$reservation,$source,$operation,$quantity,$key,$actor):array{
                $r=$this->one('SELECT * FROM pm_stock_reservations WHERE organization_id=? AND reservation_id=? FOR UPDATE',[$org,$reservation]);
                if($r===null||$r['source_ref']!==$source)throw new DomainException('Order reservation unavailable.');
                $v=$r['variant_id'];$l=$r['location_id'];
                $variant=$this->one('SELECT v.track_inventory,v.status,p.status AS product_status FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE',[$org,$v]);
                $loc=$this->one('SELECT status FROM pm_locations WHERE organization_id=? AND location_id=? FOR UPDATE',[$org,$l]);
                if($variant===null||$loc===null)throw new DomainException('Order stock identity unavailable.');
                if($operation!=='release'&&((int)$variant['track_inventory']!==1||$variant['status']!=='active'||$variant['product_status']==='archived'||$loc['status']!=='active'))throw new DomainException('Active order stock required.');
                $this->assertShopifyLocalOwnership($org,'variant',$v);
                $balance=$this->one('SELECT on_hand,reserved FROM pm_stock_balances WHERE organization_id=? AND variant_id=? AND location_id=? FOR UPDATE',[$org,$v,$l]);
                if($balance===null)throw new DomainException('Order reservation balance missing.');
                $movement=null;
                if($operation==='return'){
                    $remainingReturn=0;
                    foreach($this->rows("SELECT operation_type,quantity FROM pm_order_reservation_operations WHERE organization_id=? AND reservation_id=? AND operation_type IN ('commit','return') FOR UPDATE",[$org,$reservation]) as $fact){
                        if(!preg_match('/^[0-9]+\.000000$/D',(string)$fact['quantity']))throw new DomainException('Invalid order reservation operation quantity.');
                        $remainingReturn+=($fact['operation_type']==='commit'?1:-1)*(int)$fact['quantity'];
                    }
                    if($quantity>$remainingReturn)throw new DomainException('Return exceeds physically committed stock.');
                    $q=$this->connection->prepare('UPDATE pm_stock_balances SET on_hand=on_hand+?,revision=revision+1 WHERE organization_id=? AND variant_id=? AND location_id=?');
                    $q->execute([$quantity,$org,$v,$l]);
                }else{
                    if($r['status']!=='active')throw new DomainException('Order reservation already exhausted.');
                    $remaining=$this->one('SELECT CAST(quantity-? AS DECIMAL(20,6)) AS remaining,(quantity>=?) AS allowed FROM pm_stock_reservations WHERE organization_id=? AND reservation_id=?',[$quantity,$quantity,$org,$reservation]);
                    if((int)($remaining['allowed']??0)!==1)throw new DomainException('Settlement exceeds active reservation.');
                    $sql=$operation==='commit'?'on_hand=on_hand-?,reserved=reserved-?':'reserved=reserved-?';
                    $q=$this->connection->prepare('UPDATE pm_stock_balances SET '.$sql.',revision=revision+1 WHERE organization_id=? AND variant_id=? AND location_id=? AND reserved>=?');
                    $q->execute($operation==='commit'?[$quantity,$quantity,$org,$v,$l,$quantity]:[$quantity,$org,$v,$l,$quantity]);
                    if($q->rowCount()!==1)throw new DomainException('Order reservation projection inconsistent.');
                    if($remaining['remaining']==='0.000000'){
                        $this->execute("UPDATE pm_stock_reservations SET status='released',release_reason=?,released_at=UTC_TIMESTAMP(6),revision=revision+1 WHERE organization_id=? AND reservation_id=?",['order_'.$operation,$org,$reservation]);
                    }else{
                        $this->execute('UPDATE pm_stock_reservations SET quantity=?,revision=revision+1 WHERE organization_id=? AND reservation_id=?',[$remaining['remaining'],$org,$reservation]);
                    }
                }
                if($q->rowCount()!==1)throw new DomainException('Order stock projection update failed.');
                if($operation!=='release'){
                    $movement=bin2hex(random_bytes(16));
                    $this->execute('INSERT INTO pm_stock_movements(organization_id,movement_id,variant_id,location_id,quantity_delta,movement_type,source_ref,idempotency_key,actor_ref) VALUES(?,?,?,?,?,?,?,?,?)',
                        [$org,$movement,$v,$l,$operation==='commit'?-$quantity:$quantity,$operation==='commit'?'issue':'receipt',$source,'order-movement:'.hash('sha256',$key),$actor]);
                }
                $this->orderOperation($org,$reservation,$operation,$quantity,$movement,$source,$key,$actor);
                return ['reservation_id'=>$reservation,'operation'=>$operation,'quantity'=>$quantity,'movement_id'=>$movement,'balance'=>$this->stockBalance($org,$v,$l)];
            },'product_management.stock.order_'.$operation,'StockReservation');
    }
    private function orderAdmission(string $org,string $source,int $quantity,string $key,string $actor,string $correlation):void
    {
        if(!$this->connection->inTransaction()||$org===''||strlen($org)>40||$quantity<1||$quantity>100000||
            !preg_match('/^sales-order:[a-f0-9]{32}:[1-9][0-9]{0,3}:[a-f0-9]{32}$/D',$source)||
            $key===''||strlen($key)>191||!ctype_digit($actor)||$correlation==='')throw new DomainException('Order stock operation requires authorized native transaction and source.');
    }
    private function orderOperation(string $org,string $reservation,string $operation,int $quantity,?string $movement,string $source,string $key,string $actor):void
    {
        $this->execute('INSERT INTO pm_order_reservation_operations(organization_id,operation_id,reservation_id,operation_type,quantity,movement_id,source_ref,actor_ref) VALUES(?,?,?,?,?,?,?,?)',
            [$org,substr(hash('sha256',$key),0,32),$reservation,$operation,$quantity,$movement,$source,$actor]);
    }

    /** Native count document + correction ledger + receipt and event commit together. */
    public function applyStockCount(string $org,string $countId,string $variantId,string $locationId,string $counted,int $expectedRevision,string $source,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'stock.count.apply',$key,[$countId,$variantId,$locationId,$counted,$expectedRevision,$source],$actor,$correlation,
            function()use($org,$countId,$variantId,$locationId,$counted,$expectedRevision,$source,$key,$actor):array{
                return $this->applyCountLine($org,$countId,$variantId,$locationId,$counted,$expectedRevision,$source,$key,$actor);
            },'product_management.stock.count_applied','StockCount');
    }

    /** Caller holds the outer native receipt transaction. No nested commit. */
    private function applyCountLine(string $org,string $countId,string $variantId,string $locationId,string $counted,int $expectedRevision,string $source,string $key,string $actor):array
    {
        $variant=$this->one('SELECT v.track_inventory,v.status,p.status AS product_status FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE',[$org,$variantId]);
        if($variant===null||(int)$variant['track_inventory']!==1||$variant['status']!=='active'||$variant['product_status']==='archived')
            throw new DomainException('Active trackable Variant required.');
        $this->assertShopifyLocalOwnership($org,'variant',$variantId);
        $loc=$this->one('SELECT status FROM pm_locations WHERE organization_id=? AND location_id=? FOR UPDATE',[$org,$locationId]);
        if($loc===null||$loc['status']!=='active')throw new DomainException('Active tenant stock location required.');
        $this->execute('INSERT IGNORE INTO pm_stock_balances (organization_id,variant_id,location_id,revision) VALUES (?,?,?,0)',[$org,$variantId,$locationId]);
        $old=$this->one('SELECT on_hand,reserved,revision,CAST(CAST(? AS DECIMAL(20,6))-on_hand AS DECIMAL(20,6)) AS delta,
            (CAST(? AS DECIMAL(20,6))>=reserved) AS bounds_ok FROM pm_stock_balances WHERE organization_id=? AND variant_id=? AND location_id=? FOR UPDATE',
            [$counted,$counted,$org,$variantId,$locationId]);
        if($old===null||(int)$old['revision']!==$expectedRevision||(int)$old['bounds_ok']!==1)
            throw new DomainException('Stock changed since count or count is below reserved quantity.');
        $this->execute('UPDATE pm_stock_balances SET on_hand=?,revision=revision+1 WHERE organization_id=? AND variant_id=? AND location_id=?',
            [$counted,$org,$variantId,$locationId]);
        $movement=null;
        if($old['delta']!=='0.000000'){
            $movement=bin2hex(random_bytes(16));
            $this->execute('INSERT INTO pm_stock_movements (organization_id,movement_id,variant_id,location_id,quantity_delta,movement_type,source_ref,idempotency_key,actor_ref) VALUES (?,?,?,?,?,?,?,?,?)',
                [$org,$movement,$variantId,$locationId,$old['delta'],'adjustment',$source,$key,$actor]);
        }
        $this->execute('INSERT INTO pm_stock_counts (organization_id,count_id,variant_id,location_id,source_ref,counted,previous_on_hand,reserved,delta,expected_revision,result_revision,movement_id,action_key,actor_ref)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$org,$countId,$variantId,$locationId,$source,$counted,$old['on_hand'],$old['reserved'],$old['delta'],$expectedRevision,$expectedRevision+1,$movement,$key,$actor]);
        $result=$this->getStockCount($org,$countId);
        if($result===null)throw new DomainException('Count receipt missing.');
        return $result;
    }

    public function applyCountSession(string $org,string $sessionId,string $location,array $rows,string $source,string $key,string $actor,string $correlation):array
    {
        return $this->mutate($org,'stock.count.session.apply',$key,[$sessionId,$location,$rows,$source],$actor,$correlation,
            function()use($org,$sessionId,$location,$rows,$source,$key,$actor):array{
                $this->execute('INSERT INTO pm_stock_count_sessions (organization_id,session_id,location_id,source_ref,action_key,actor_ref,row_count) VALUES (?,?,?,?,?,?,?)',
                    [$org,$sessionId,$location,$source,$key,$actor,count($rows)]);
                foreach($rows as $row){
                    $variant=$row['variant_id'];
                    $id=substr(hash('sha256',$sessionId.':'.$variant),0,32);
                    // Child identity is derived from the one approved parent Action; never dispatched as an Action.
                    $lineKey='wf:'.hash('sha256',$key.':'.$variant);
                    $this->applyCountLine($org,$id,$variant,$location,$row['counted'],$row['expected_revision'],$source,$lineKey,$actor);
                    $this->execute('INSERT INTO pm_stock_count_session_lines (organization_id,session_id,count_id) VALUES (?,?,?)',[$org,$sessionId,$id]);
                }
                $result=$this->getCountSession($org,$sessionId);
                if($result===null||count($result['rows'])!==count($rows))throw new DomainException('Session receipt incomplete.');
                return $result;
            },'product_management.stock.count_session_applied','StockCountSession');
    }

    public function countCandidates(string $org,string $location,int $limit,int $offset):array
    {
        $sql="SELECT v.variant_id,v.sku,p.name,CAST(COALESCE(b.on_hand,0) AS DECIMAL(20,6)) AS on_hand,
            CAST(COALESCE(b.reserved,0) AS DECIMAL(20,6)) AS reserved,COALESCE(b.revision,0) AS revision
            FROM pm_locations l JOIN pm_variants v ON v.organization_id=l.organization_id
            JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id
            LEFT JOIN pm_stock_balances b ON b.organization_id=v.organization_id AND b.variant_id=v.variant_id AND b.location_id=l.location_id
            WHERE l.organization_id=:org AND l.location_id=:loc AND l.status='active' AND v.status='active'
              AND v.track_inventory=1 AND p.status<>'archived'
              AND NOT EXISTS (SELECT 1 FROM pm_external_mappings m JOIN pm_external_connections c ON c.organization_id=m.organization_id AND c.connection_id=m.connection_id
                WHERE m.organization_id=v.organization_id AND ((m.entity_type='variant' AND m.canonical_id=v.variant_id) OR (m.entity_type='product' AND m.canonical_id=v.product_id))
                AND c.provider='shopify' AND c.mode='shopify_authoritative' AND c.status='ready')
            ORDER BY v.variant_id LIMIT :lim OFFSET :off";
        $stmt=$this->connection->prepare($sql);
        $stmt->bindValue(':org',$org);$stmt->bindValue(':loc',$location);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);$stmt->execute();
        return array_map(static function(array $row):array{$row['revision']=(int)$row['revision'];return $row;},$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getCountSession(string $org,string $sessionId):?array
    {
        $head=$this->one('SELECT session_id,location_id,source_ref,action_key,actor_ref,row_count,created_at FROM pm_stock_count_sessions WHERE organization_id=? AND session_id=?',[$org,$sessionId]);
        if($head===null)return null;
        $head['row_count']=(int)$head['row_count'];
        $links=$this->rows('SELECT c.count_id FROM pm_stock_count_session_lines l JOIN pm_stock_counts c ON c.organization_id=l.organization_id AND c.count_id=l.count_id WHERE l.organization_id=? AND l.session_id=? ORDER BY c.variant_id',[$org,$sessionId]);
        $head['rows']=array_map(fn(array $link):array=>$this->getStockCount($org,$link['count_id'])??throw new DomainException('Count evidence missing.'),$links);
        return $head;
    }

    public function getStockCount(string $org,string $countId):?array
    {
        $row=$this->one('SELECT count_id,variant_id,location_id,source_ref,counted,previous_on_hand,reserved,delta,expected_revision,result_revision,movement_id,action_key,actor_ref,created_at
            FROM pm_stock_counts WHERE organization_id=? AND count_id=?',[$org,$countId]);
        if($row!==null){
            $row['expected_revision']=(int)$row['expected_revision'];$row['result_revision']=(int)$row['result_revision'];
        }
        return $row;
    }

    private function decodeAttribute(array $row):array
    {
        $row['choices']=json_decode($row['choices_json'],true,flags:JSON_THROW_ON_ERROR);unset($row['choices_json']);
        foreach(['is_required','is_axis'] as $flag)if(isset($row[$flag]))$row[$flag]=(bool)$row[$flag];
        return $row;
    }
    private function attributeDefinition(string $org,string $code,bool $lock=false):array
    {
        $row=$this->one("SELECT code,name,value_type,unit,choices_json FROM pm_attribute_definitions WHERE organization_id=? AND code=? AND lifecycle_status='active'".($lock?' FOR UPDATE':''),[$org,$code]);
        if($row===null)throw new DomainException('Unknown tenant attribute.');
        return $this->decodeAttribute($row);
    }
    public function defineAttribute(string $org,array $definition,string $key,string $actor,string $trace):array
    {
        return $this->mutate($org,'attribute.define',$key,[$definition],$actor,$trace,function()use($org,$definition):array{
            $this->execute('INSERT INTO pm_attribute_definitions (organization_id,code,name,value_type,unit,choices_json) VALUES (?,?,?,?,?,?)',
                [$org,$definition['code'],$definition['name'],$definition['value_type'],$definition['unit'],json_encode($definition['choices'],JSON_THROW_ON_ERROR)]);
            return $this->attributeDefinition($org,$definition['code']);
        },'product_management.attribute.defined','Attribute');
    }
    public function attributes(string $org,int $limit,int $offset,string $status='active'):array
    {
        $stmt=$this->connection->prepare('SELECT code,name,value_type,unit,choices_json FROM pm_attribute_definitions WHERE organization_id=:org AND lifecycle_status=:status ORDER BY code LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$org);$stmt->bindValue(':status',$status);$stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);$stmt->execute();
        return array_map($this->decodeAttribute(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    public function bindAttribute(string $org,string $category,string $code,bool $required,bool $axis,string $key,string $actor,string $trace):array
    {
        return $this->mutate($org,'attribute.category.bind',$key,[$category,$code,$required,$axis],$actor,$trace,function()use($org,$category,$code,$required,$axis):array{
            $def=$this->attributeDefinition($org,$code,true);
            if($axis&&$def['value_type']!=='enum')throw new DomainException('Matrix axis must be enum.');
            if($this->one('SELECT category_id FROM pm_categories WHERE organization_id=? AND category_id=? FOR UPDATE',[$org,$category])===null)
                throw new DomainException('Unknown tenant category.');
            $this->execute('INSERT INTO pm_category_attributes (organization_id,category_id,code,is_required,is_axis) VALUES (?,?,?,?,?)',[$org,$category,$code,(int)$required,(int)$axis]);
            return ['category_id'=>$category,'code'=>$code,'is_required'=>$required,'is_axis'=>$axis];
        },'product_management.attribute.category_bound','Category');
    }
    public function changeAttributeLifecycle(string $org,array $in,string $key,string $actor,string $trace):array
    {
        return $this->mutate($org,'attribute.lifecycle.change',$key,[$in],$actor,$trace,function()use($org,$in):array{
            $code=$in['code'];$op=$in['operation'];
            $def=$this->one('SELECT code,name,value_type,lifecycle_status FROM pm_attribute_definitions WHERE organization_id=? AND code=? FOR UPDATE',[$org,$code]);
            if($def===null)throw new DomainException('Unknown tenant attribute.');
            $result=['operation'=>$op,'code'=>$code];
            if(in_array($op,['rename','archive','restore'],true)){
                $expectedStatus=$op==='restore'?'archived':'active';
                if($def['lifecycle_status']!==$expectedStatus||$def['name']!==$in['expected_name'])throw new DomainException('Attribute state conflict.');
                if($op==='rename'){
                    $this->execute('UPDATE pm_attribute_definitions SET name=? WHERE organization_id=? AND code=?',[$in['name'],$org,$code]);
                    return $result+['name'=>$in['name'],'status'=>'active'];
                }
                if($op==='archive'){
                    if($this->one('SELECT code FROM pm_category_attributes WHERE organization_id=? AND code=? LIMIT 1 FOR UPDATE',[$org,$code])!==null
                        ||$this->one('SELECT code FROM pm_product_attribute_values WHERE organization_id=? AND code=? LIMIT 1 FOR UPDATE',[$org,$code])!==null)
                        throw new DomainException('Cannot archive an attribute still in use.');
                }
                $status=$op==='archive'?'archived':'active';
                $this->execute('UPDATE pm_attribute_definitions SET lifecycle_status=? WHERE organization_id=? AND code=?',[$status,$org,$code]);
                return $result+['name'=>$def['name'],'status'=>$status];
            }
            if($def['lifecycle_status']!=='active')throw new DomainException('Active attribute required.');
            $category=$in['category_id'];
            if($this->one('SELECT category_id FROM pm_categories WHERE organization_id=? AND category_id=? FOR UPDATE',[$org,$category])===null)
                throw new DomainException('Unknown tenant category.');
            $row=$this->one('SELECT is_required,is_axis FROM pm_category_attributes WHERE organization_id=? AND category_id=? AND code=? FOR UPDATE',[$org,$category,$code]);
            if($row===null||(bool)$row['is_required']!==$in['expected_is_required']||(bool)$row['is_axis']!==$in['expected_is_axis'])
                throw new DomainException('Category binding state conflict.');
            // Deliberately conservative: no schema change under products or existing SKU matrices.
            if($this->one('SELECT product_id FROM pm_product_categories WHERE organization_id=? AND category_id=? LIMIT 1 FOR UPDATE',[$org,$category])!==null)
                throw new DomainException('Category is in use; migrate its products before changing bindings.');
            if($op==='binding.remove'){
                $this->execute('DELETE FROM pm_category_attributes WHERE organization_id=? AND category_id=? AND code=?',[$org,$category,$code]);
                return $result+['category_id'=>$category,'bound'=>false];
            }
            if($op!=='binding.update'||($in['is_axis']&&$def['value_type']!=='enum'))throw new DomainException('Invalid binding update.');
            $this->execute('UPDATE pm_category_attributes SET is_required=?,is_axis=? WHERE organization_id=? AND category_id=? AND code=?',
                [(int)$in['is_required'],(int)$in['is_axis'],$org,$category,$code]);
            return $result+['category_id'=>$category,'bound'=>true,'is_required'=>$in['is_required'],'is_axis'=>$in['is_axis']];
        },'product_management.attribute.lifecycle_changed','Attribute');
    }

    public function categoryAttributes(string $org,string $category):array
    {
        return array_map($this->decodeAttribute(...),$this->rows('SELECT d.code,d.name,d.value_type,d.unit,d.choices_json,a.is_required,a.is_axis FROM pm_category_attributes a JOIN pm_attribute_definitions d ON d.organization_id=a.organization_id AND d.code=a.code WHERE a.organization_id=? AND a.category_id=? ORDER BY d.code',[$org,$category]));
    }
    private function applicableAttributes(string $org,string $product):array
    {
        $rows=$this->rows('SELECT d.code,d.name,d.value_type,d.unit,d.choices_json,x.is_required,x.is_axis FROM (SELECT pc.organization_id,a.code,MAX(a.is_required) AS is_required,MAX(a.is_axis) AS is_axis FROM pm_product_categories pc JOIN pm_category_attributes a ON a.organization_id=pc.organization_id AND a.category_id=pc.category_id WHERE pc.organization_id=? AND pc.product_id=? GROUP BY pc.organization_id,a.code) x JOIN pm_attribute_definitions d ON d.organization_id=x.organization_id AND d.code=x.code ORDER BY d.code',[$org,$product]);
        $map=[];foreach($rows as $row)$map[$row['code']]=$this->decodeAttribute($row);
        return $map;
    }
    private function lockAttributeProduct(string $org,string $product,int $revision):array
    {
        $row=$this->one('SELECT product_type,status,revision FROM pm_products WHERE organization_id=? AND product_id=? FOR UPDATE',[$org,$product]);
        if($row===null||$row['status']==='archived'||(int)$row['revision']!==$revision)throw new DomainException('Active Product and current revision required.');
        $this->assertShopifyLocalOwnership($org,'product',$product);return $row;
    }
    public function setAttributes(string $org,string $product,array $changes,int $revision,string $key,string $actor,string $trace):array
    {
        return $this->mutate($org,'attribute.product.set',$key,[$product,$changes,$revision],$actor,$trace,function()use($org,$product,$changes,$revision):array{
            $this->applyAttributePatch($org,$product,$changes,$revision);
            return $this->productAttributes($org,$product);
        },'product_management.attribute.product_set','Product');
    }
    private function applyAttributePatch(string $org,string $product,array $changes,int $revision):void
    {
    $this->lockAttributeProduct($org,$product,$revision);
    $defs=$this->applicableAttributes($org,$product);
    foreach($changes as $code=>$value){
        if(!isset($defs[$code]))throw new DomainException('Attribute not in assigned category set.');
        if($value===null){
            if($defs[$code]['is_required'])throw new DomainException('Required value cannot be removed.');
            $this->execute('DELETE FROM pm_product_attribute_values WHERE organization_id=? AND product_id=? AND code=?',[$org,$product,$code]);
        }else{
            $value=AttributeRules::value($defs[$code],$value);
            $this->execute('INSERT INTO pm_product_attribute_values (organization_id,product_id,code,value_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)',[$org,$product,$code,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        }
    }
    $this->execute('UPDATE pm_products SET revision=revision+1 WHERE organization_id=? AND product_id=?',[$org,$product]);
    }

    public function bulkSetAttributes(string $org,array $products,array $changes,string $key,string $actor,string $trace):array
    {
        return $this->mutate($org,'attribute.product.bulk_set',$key,[$products,$changes],$actor,$trace,function()use($org,$products,$changes):array{
            $items=[];
            foreach($products as $id=>$revision){
                $this->applyAttributePatch($org,(string)$id,$changes,$revision);
                $after=$this->productAttributes($org,(string)$id);
                $values=[];foreach($after['items'] as $row)if(array_key_exists($row['code'],$changes))$values[]=['code'=>$row['code'],'value'=>$row['value']];
                if(count($values)!==count($changes))throw new DomainException('Bulk attribute evidence missing.');
                $items[]=['product_id'=>(string)$id,'revision'=>$revision+1,'values'=>$values];
            }
            return ['items'=>$items];
        },'product_management.attribute.bulk_set','ProductAttributeBatch');
    }

    public function productAttributes(string $org,string $product):array
    {
        $p=$this->one('SELECT revision FROM pm_products WHERE organization_id=? AND product_id=?',[$org,$product]);
        if($p===null)return ['found'=>false,'product_id'=>$product,'revision'=>0,'items'=>[]];
        $values=[];foreach($this->rows('SELECT code,value_json FROM pm_product_attribute_values WHERE organization_id=? AND product_id=?',[$org,$product]) as $row)$values[$row['code']]=json_decode($row['value_json'],true,flags:JSON_THROW_ON_ERROR);
        $items=[];foreach($this->applicableAttributes($org,$product) as $code=>$def)$items[]=$def+['value'=>$values[$code]??null,'missing_required'=>$def['is_required']&&!array_key_exists($code,$values)];
        return ['found'=>true,'product_id'=>$product,'revision'=>(int)$p['revision'],'items'=>$items];
    }
    public function filterAttributes(string $org,string $code,mixed $value,int $limit,int $offset):array
    {
        $value=AttributeRules::value($this->attributeDefinition($org,$code),$value);
        $stmt=$this->connection->prepare("SELECT p.product_id,p.name,p.status,p.revision FROM pm_product_attribute_values a JOIN pm_products p ON p.organization_id=a.organization_id AND p.product_id=a.product_id WHERE a.organization_id=:org AND a.code=:code AND JSON_CONTAINS(a.value_json,CAST(:value AS JSON)) AND p.status<>'archived' ORDER BY p.product_id LIMIT :lim OFFSET :off");
        $stmt->bindValue(':org',$org);$stmt->bindValue(':code',$code);$stmt->bindValue(':value',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);$stmt->execute();
        return array_map(static function(array $row):array{$row['revision']=(int)$row['revision'];return $row;},$stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    public function variantMatrix(string $org,string $product,array $matrix):array
    {
        $p=$this->one('SELECT status,revision FROM pm_products WHERE organization_id=? AND product_id=?',[$org,$product]);
        if($p===null||$p['status']==='archived')throw new DomainException('Active tenant Product required.');
        $defs=$this->applicableAttributes($org,$product);
        $existing=[];
        foreach($this->rows('SELECT variant_id,sku,options_json FROM pm_variants WHERE organization_id=? AND product_id=?',[$org,$product]) as $v){
            if($v['options_json']===null)continue;
            $options=json_decode($v['options_json'],true,flags:JSON_THROW_ON_ERROR);
            if(!is_array($options)||$options===[])continue;
            ksort($options,SORT_STRING);$existing[hash('sha256',json_encode($options,JSON_THROW_ON_ERROR))]=$v['variant_id'];
        }
        $items=[];
        foreach($matrix as $row){
            foreach($row['options'] as $code=>$value){
                if(!isset($defs[$code])||!$defs[$code]['is_axis'])throw new DomainException('Matrix axis not assigned to Product category.');
                AttributeRules::value($defs[$code],$value);
            }
            $sku=$this->one('SELECT variant_id FROM pm_variants WHERE organization_id=? AND sku=?',[$org,$row['sku']]);
            $items[]=$row+['status'=>isset($existing[$row['combination_hash']])?'exists':($sku!==null?'sku_conflict':'ready')];
        }
        return ['product_id'=>$product,'revision'=>(int)$p['revision'],'items'=>$items];
    }
    public function createMatrix(string $org,string $product,array $matrix,int $revision,string $uom,bool $track,string $key,string $actor,string $trace):array
    {
        return $this->mutate($org,'variant.matrix.create',$key,[$product,$matrix,$revision,$uom,$track],$actor,$trace,function()use($org,$product,$matrix,$revision,$uom,$track):array{
            $p=$this->lockAttributeProduct($org,$product,$revision);
            if($track&&in_array($p['product_type'],['service','digital'],true))throw new DomainException('Non-stock matrix cannot track inventory.');
            $preview=$this->variantMatrix($org,$product,$matrix);$items=[];
            foreach($preview['items'] as $row){
                if($row['status']!=='ready')throw new DomainException('Matrix contains an existing combination or SKU conflict.');
                $id=bin2hex(random_bytes(16));
                $this->execute('INSERT INTO pm_variants (organization_id,variant_id,product_id,sku,uom,track_inventory,options_json) VALUES (?,?,?,?,?,?,?)',[$org,$id,$product,$row['sku'],$uom,(int)$track,json_encode($row['options'],JSON_THROW_ON_ERROR)]);
                $this->execute('INSERT INTO pm_variant_matrix_identities (organization_id,product_id,variant_id,combination_hash) VALUES (?,?,?,?)',[$org,$product,$id,$row['combination_hash']]);
                $items[]=['variant_id'=>$id,'sku'=>$row['sku'],'combination_hash'=>$row['combination_hash']];
            }
            $this->execute('UPDATE pm_products SET revision=revision+1 WHERE organization_id=? AND product_id=?',[$org,$product]);
            return ['product_id'=>$product,'revision'=>$revision+1,'items'=>$items];
        },'product_management.variant.matrix_created','Product');
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
        $outer=$this->connection->inTransaction();
        $savepoint='pm_'.bin2hex(random_bytes(8));
        if($outer)$this->connection->exec('SAVEPOINT '.$savepoint);else $this->connection->beginTransaction();
        try {
            try {
                $this->execute('INSERT INTO pm_operation_receipts (organization_id,idempotency_key,operation,payload_hash) VALUES (?,?,?,?)',
                    [$org,$key,$operation,$hash]);
            } catch (PDOException $e) {
                if ($e->getCode()!=='23000') throw $e;
                $existing=$this->one('SELECT payload_hash,result_json FROM pm_operation_receipts WHERE organization_id=? AND idempotency_key=?',[$org,$key]);
                if ($existing===null||!hash_equals((string)$existing['payload_hash'],$hash)||$existing['result_json']===null) throw new DomainException('Idempotency conflict or uncommitted result.');
                if($outer)$this->connection->exec('RELEASE SAVEPOINT '.$savepoint);else $this->connection->commit();
                return json_decode((string)$existing['result_json'],true,flags:JSON_THROW_ON_ERROR);
            }
            $result=$callback();
            $id=(string)($result['product_id']??$result['session_id']??$result['count_id']??$result['reservation_id']??$result['variant_id']??$result['category_id']??$result['code']??$result['location_id']??$result['movement_id']??bin2hex(random_bytes(16)));
            $event=new DomainEvent(bin2hex(random_bytes(16)),$org,$eventType,$aggregateType,$id,
                ['operation'=>$operation,'result'=>$result],new EventMetadata(strlen($correlation)<=40?$correlation:substr(hash('sha256',$correlation),0,40),null,str_starts_with($actor,'shopify:')?'INTEGRATION':'USER',$actor),new \DateTimeImmutable('now',new \DateTimeZone('UTC')));
            $this->events->append($event); // writes cos_events + cos_event_outbox in this same transaction
            $this->execute('UPDATE pm_operation_receipts SET result_json=? WHERE organization_id=? AND idempotency_key=?',
                [json_encode($result,JSON_THROW_ON_ERROR),$org,$key]);
            if($outer)$this->connection->exec('RELEASE SAVEPOINT '.$savepoint);else $this->connection->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                if($outer){$this->connection->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$this->connection->exec('RELEASE SAVEPOINT '.$savepoint);}
                else $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Preserve Shopify-authoritative catalog/stock ownership. A local owner
     * switch requires a separately reviewed policy/cutover, not a hidden
     * override in a generic Product or Stock command.
     */
    private function assertShopifyLocalOwnership(string $org,string $entityType,string $id):void
    {
        if(!in_array($entityType,['product','variant'],true))
            throw new DomainException('Invalid Product ownership entity.');
        $found=$this->one("SELECT m.canonical_id FROM pm_external_mappings m
            INNER JOIN pm_external_connections c
                ON c.organization_id=m.organization_id AND c.connection_id=m.connection_id
            LEFT JOIN pm_variants v
                ON v.organization_id=m.organization_id AND v.variant_id=?
            WHERE m.organization_id=?
                AND (
                    (m.entity_type=? AND m.canonical_id=?)
                    OR (?='variant' AND m.entity_type='product' AND m.canonical_id=v.product_id)
                )
                AND c.provider='shopify' AND c.mode='shopify_authoritative' AND c.status='ready'
            LIMIT 1",[$id,$org,$entityType,$id,$entityType]);
        if($found!==null)
            throw new DomainException('Shopify-authoritative identity requires controlled owner cutover.');
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
