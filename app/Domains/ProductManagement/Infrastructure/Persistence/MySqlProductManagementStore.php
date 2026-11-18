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
                $product=$this->one('SELECT product_type FROM pm_products WHERE organization_id=? AND product_id=?',[$organizationId,$productId]);
                if ($product===null) throw new DomainException('Unknown product.');
                if (in_array($product['product_type'],['service','digital'],true)&&$track) throw new DomainException('Non-stock product cannot track inventory.');
                $id=bin2hex(random_bytes(16));
                $this->execute('INSERT INTO pm_variants (organization_id,variant_id,product_id,sku,uom,track_inventory) VALUES (?,?,?,?,?,?)',
                    [$organizationId,$id,$productId,$sku,$uom,$track?1:0]);
                return ['product_id'=>$productId,'variant_id'=>$id,'sku'=>$sku];
            },'product_management.variant.created','Product');
    }

    public function getProduct(string $organizationId, string $id): ?array
    {
        $row = $this->one('SELECT * FROM pm_products WHERE organization_id=? AND product_id=?', [$organizationId,$id]);
        if ($row === null) return null;
        $row['variants'] = $this->rows('SELECT * FROM pm_variants WHERE organization_id=? AND product_id=? ORDER BY sku', [$organizationId,$id]);
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
                $variant=$this->one('SELECT track_inventory,status FROM pm_variants WHERE organization_id=? AND variant_id=?', [$organizationId,$variantId]);
                $location=$this->one('SELECT status FROM pm_locations WHERE organization_id=? AND location_id=?', [$organizationId,$locationId]);
                if ($variant === null || (int)$variant['track_inventory'] !== 1 || $variant['status'] !== 'active'
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
            $id=(string)($result['product_id']??$result['location_id']??$result['movement_id']??bin2hex(random_bytes(16)));
            $event=new DomainEvent(bin2hex(random_bytes(16)),$org,$eventType,$aggregateType,$id,
                ['operation'=>$operation,'result'=>$result],new EventMetadata($correlation,null,str_starts_with($actor,'shopify:')?'EXTERNAL':'HUMAN',$actor),new \DateTimeImmutable('now',new \DateTimeZone('UTC')));
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
