<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use PDO;

/** Tenant-owned integration observations; never changes local stock ledger. */
final readonly class ShopifyObservationStore
{
    public function __construct(private PDO $connection) {}

    /** @return array<string,mixed> */
    public function connection(string $org,string $id): array
    {
        $row=$this->query('SELECT * FROM pm_external_connections WHERE organization_id=? AND connection_id=? AND provider=?',[$org,$id,'shopify'])->fetch(PDO::FETCH_ASSOC);
        if ($row===false || $row['status']!=='ready') throw new DomainException('Active Shopify connection is not configured.');
        return $row;
    }

    /**
     * Webhook routing must not trust caller tenant identifiers. We only accept
     * an active shop configured for exactly one tenant; ambiguity blocks ingress.
     * @return array{organization_id:string,connection_id:string,secret_ref:string}
     */
    public function uniqueConnectionForShop(string $shopDomain): array
    {
        ShopifyReadClient::assertShopDomain($shopDomain);
        $rows=$this->query('SELECT organization_id,connection_id,secret_ref FROM pm_external_connections WHERE provider=? AND shop_domain=? AND status=? LIMIT 2',
            ['shopify',$shopDomain,'ready'])->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)!==1) throw new DomainException('Shopify webhook shop is unknown or ambiguous.');
        return ['organization_id'=>(string)$rows[0]['organization_id'],
            'connection_id'=>(string)$rows[0]['connection_id'],
            'secret_ref'=>(string)$rows[0]['secret_ref']];
    }

    /** Explicit operator-only provisioning, never from a webhook payload. */
    public function provision(string $org,string $shopDomain,string $secretRef,?string $mode=null): string
    {
        ShopifyReadClient::assertShopDomain($shopDomain);
        if ($org===''||strlen($org)>190) throw new DomainException('Unsupported connection provisioning.');
        ShopifyClientCredentials::assertSecretReference($secretRef);
        if($mode!==null&&!in_array($mode,['shopify_authoritative','observation_only'],true))throw new DomainException('Unsupported connection ownership.');
        // Shopify webhook routing requires exactly one active connection per shop.
        // Serialize provisioning across all tenants to avoid duplicate ready
        // connections when an operator retries a timed-out cutover job.
        $lock='pm-shop:'.substr(hash('sha256',$shopDomain),0,48);
        $held=(int)$this->query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn();
        if($held!==1) throw new DomainException('Shopify provisioning is already in progress.');
        try {
            $ready=$this->query('SELECT organization_id,connection_id,secret_ref,mode FROM pm_external_connections
                WHERE provider=? AND shop_domain=? AND status=? LIMIT 2',
                ['shopify',$shopDomain,'ready'])->fetchAll(PDO::FETCH_ASSOC);
            if(count($ready)>1) throw new DomainException('Shopify shop has ambiguous existing ready connections.');
            if(count($ready)===1) {
                if((string)$ready[0]['organization_id']!==$org
                    ||(string)$ready[0]['secret_ref']!==$secretRef||($mode!==null&&$ready[0]['mode']!==$mode)) {
                    throw new DomainException('Shopify shop already belongs to another owner or credential reference.');
                }
                return (string)$ready[0]['connection_id'];
            }
            $id=bin2hex(random_bytes(16));
            $this->query('INSERT INTO pm_external_connections (organization_id,connection_id,provider,shop_domain,secret_ref,status,mode) VALUES (?,?,?,?,?,?,?)',
                [$org,$id,'shopify',$shopDomain,$secretRef,'ready',$mode??'shopify_authoritative']);
            return $id;
        } finally {
            $this->query('SELECT RELEASE_LOCK(?)',[$lock]);
        }
    }

    /** @return array<string,mixed>|null */
    public function mapping(string $org,string $conn,string $type,string $gid): ?array
    {
        $row=$this->query('SELECT canonical_id FROM pm_external_mappings WHERE organization_id=? AND connection_id=? AND entity_type=? AND external_gid=?',
            [$org,$conn,$type,$gid])->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    public function map(string $org,string $conn,string $type,string $gid,string $canonical): void
    {
        if (!in_array($type,['product','variant'],true)||!preg_match('#^gid://shopify/(?:Product|ProductVariant)/[0-9]+$#D',$gid)
            ||!preg_match('/^[a-f0-9]{32}$/D',$canonical)) throw new DomainException('Invalid external mapping.');
        $this->query('INSERT INTO pm_external_mappings (organization_id,connection_id,entity_type,external_gid,canonical_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE canonical_id=canonical_id',
            [$org,$conn,$type,$gid,$canonical]);
        $existing=$this->mapping($org,$conn,$type,$gid);
        if ($existing===null || $existing['canonical_id']!==$canonical) throw new DomainException('External identity collision.');
    }

    /** @param array<string,mixed> $snapshot */
    public function observeProduct(string $org,string $conn,string $gid,array $snapshot,bool $complete=false): void
    {
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        $this->query('INSERT INTO pm_external_product_snapshots (organization_id,connection_id,product_gid,payload_hash,payload_json,is_complete,observed_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE payload_hash=VALUES(payload_hash),payload_json=VALUES(payload_json),is_complete=VALUES(is_complete),observed_at=UTC_TIMESTAMP(6)',
            [$org,$conn,$gid,hash('sha256',$json),$json,$complete?1:0]);
    }

    /** Shop currency is authenticated provider evidence, never a user-supplied guess. */
    public function recordShopCurrency(string $org,string $conn,string $currency):void
    {
        if (!preg_match('/^[A-Z]{3}$/D',$currency)) throw new DomainException('Shopify reported invalid currency.');
        $stmt=$this->query('UPDATE pm_external_connections SET shop_currency=?,shop_currency_checked_at=UTC_TIMESTAMP(6)
            WHERE organization_id=? AND connection_id=? AND provider=? AND status=?',
            [$currency,$org,$conn,'shopify','ready']);
        if ($stmt->rowCount()!==1) throw new DomainException('Missing Shopify currency connection scope.');
    }


    /**
     * Tenant-scoped, bounded candidate metadata for supervised Shopify Preview.
     * Raw product data is never returned and canonical Product is not changed.
     * @return list<array<string,mixed>>
     */
    public function candidates(string $org,string $conn,int $limit=20):array
    {
        if ($limit<1||$limit>50) throw new DomainException('Invalid Shopify candidate limit.');
        $connection=$this->connection($org,$conn);
        $currencyVerified=is_string($connection['shop_currency']??null)
            && preg_match('/^[A-Z]{3}$/D',$connection['shop_currency'])===1
            && is_string($connection['shop_currency_checked_at']??null)
            && strtotime($connection['shop_currency_checked_at'].' UTC')!==false
            && strtotime($connection['shop_currency_checked_at'].' UTC')>=time()-86400;
        // LIMIT is checked as an integer above; no raw JSON is selected.
        $rows=$this->query('SELECT product_gid,payload_hash,is_complete,observed_at,
                (observed_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 24 HOUR)) AS fresh
                FROM pm_external_product_snapshots WHERE organization_id=? AND connection_id=?
                ORDER BY observed_at DESC,product_gid ASC LIMIT '.$limit,
            [$org,$conn])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function(array $row)use($currencyVerified):array {
            $complete=(int)$row['is_complete']===1;
            $fresh=(int)$row['fresh']===1;
            return [
                'product_gid'=>(string)$row['product_gid'],
                'source_hash'=>(string)$row['payload_hash'],
                'complete'=>$complete,
                'fresh'=>$fresh,
                'shop_currency_verified'=>$currencyVerified,
                // SKU, mapping and Plan conflicts are still checked on stage.
                'stage_preconditions_met'=>$complete&&$fresh&&$currencyVerified,
                'observed_at'=>(string)$row['observed_at'],
            ];
        },$rows);
    }

    /** @return array<string,mixed>|null */
    public function productSnapshot(string $org,string $conn,string $gid):?array
    {
        $row=$this->query('SELECT product_gid,payload_hash,payload_json,is_complete,observed_at
            FROM pm_external_product_snapshots WHERE organization_id=? AND connection_id=? AND product_gid=?',
            [$org,$conn,$gid])->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    /** @param array<string,mixed> $level */
    public function observeStock(string $org,string $conn,string $variantGid,string $inventoryGid,array $level): void
    {
        $location=$level['location']['id']??null;
        if (!is_string($location)||!preg_match('#^gid://shopify/Location/[0-9]+$#D',$location)) throw new DomainException('Invalid Shopify location.');
        $qty=[];
        foreach ($level['quantities']??[] as $q) {
            if (in_array($q['name']??null,['on_hand','available'],true)&&is_int($q['quantity']??null)) {
                $qty[$q['name']]=$q['quantity'];
            }
        }
        $this->query('INSERT INTO pm_external_stock_observations (organization_id,connection_id,variant_gid,location_gid,inventory_gid,on_hand,available) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE inventory_gid=VALUES(inventory_gid),on_hand=VALUES(on_hand),available=VALUES(available),observed_at=NOW(6)',
            [$org,$conn,$variantGid,$location,$inventoryGid,$qty['on_hand']??null,$qty['available']??null]);
    }

    public function conflict(string $org,string $conn,string $gid,string $reason,array $details=[]): void
    {
        $this->query('INSERT IGNORE INTO pm_sync_conflicts (organization_id,conflict_id,connection_id,external_gid,reason,detail_json) VALUES (?,?,?,?,?,?)',
            [$org,bin2hex(random_bytes(16)),$conn,mb_substr($gid,0,191),mb_substr($reason,0,80),json_encode($details,JSON_THROW_ON_ERROR)]);
    }

    /**
     * Atomic, tenant-scoped sync lease. A stale pull may be replaced only
     * after its lease expires; subsequent stale checkpoints are fenced.
     */
    public function start(string $org,string $conn): string
    {
        $run=bin2hex(random_bytes(16));
        $this->connection->beginTransaction();
        try {
            $row=$this->query('SELECT sync_run_id, (sync_lease_expires_at>UTC_TIMESTAMP(6)) AS lease_active FROM pm_external_connections WHERE organization_id=? AND connection_id=? AND status=? FOR UPDATE',
                [$org,$conn,'ready'])->fetch(PDO::FETCH_ASSOC);
            if ($row===false) throw new DomainException('Unknown or disabled Shopify connection.');
            if ($row['sync_run_id']!==null && (int)$row['lease_active']===1) {
                throw new DomainException('Shopify sync already has an active tenant lease.');
            }
            $this->query('INSERT INTO pm_sync_runs (organization_id,run_id,connection_id) VALUES (?,?,?)',
                [$org,$run,$conn]);
            $this->query('UPDATE pm_external_connections SET sync_run_id=?,sync_lease_expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 15 MINUTE) WHERE organization_id=? AND connection_id=?',
                [$run,$org,$conn]);
            $this->connection->commit();
            return $run;
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $e;
        }
    }

    public function checkpoint(string $org,string $conn,string $run,string $cursor,int $count,int $conflicts,bool $finished): void
    {
        if (strlen($cursor)>512 || $count<0 || $conflicts<0) {
            throw new DomainException('Invalid bounded Shopify checkpoint.');
        }
        $this->connection->beginTransaction();
        try {
            $row=$this->query('SELECT sync_run_id FROM pm_external_connections WHERE organization_id=? AND connection_id=? FOR UPDATE',
                [$org,$conn])->fetch(PDO::FETCH_ASSOC);
            if ($row===false || $row['sync_run_id']!==$run) {
                throw new DomainException('Shopify checkpoint denied: stale or foreign lease.');
            }
            $updated=$this->query("UPDATE pm_sync_runs SET pages=pages+1,observed_products=observed_products+?,conflicts=conflicts+?,page_cursor=?,state=? WHERE organization_id=? AND connection_id=? AND run_id=? AND state IN ('running','paused')",
                [$count,$conflicts,$cursor,$finished?'completed':'paused',$org,$conn,$run]);
            if ($updated->rowCount()!==1) throw new DomainException('Shopify sync run missing or no longer writable.');
            $this->query('UPDATE pm_external_connections SET page_cursor=?,last_sync_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP(6) ELSE last_sync_at END,sync_lease_expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 15 MINUTE) WHERE organization_id=? AND connection_id=? AND sync_run_id=?',
                [$finished?null:$cursor,$finished?1:0,$org,$conn,$run]);
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $e;
        }
    }

    public function release(string $org,string $conn,string $run): void
    {
        $stmt=$this->query('UPDATE pm_external_connections SET sync_run_id=NULL,sync_lease_expires_at=NULL WHERE organization_id=? AND connection_id=? AND sync_run_id=?',
            [$org,$conn,$run]);
        if ($stmt->rowCount()!==1) throw new DomainException('Stale Shopify sync cannot release another run.');
    }

    public function fail(string $org,string $run,string $code): void
    {
        $this->query("UPDATE pm_sync_runs SET state='error',error_code=? WHERE organization_id=? AND run_id=?",
            [mb_substr($code,0,190),$org,$run]);
        // Compare-and-release never clears a replacement run's lease.
        $this->query('UPDATE pm_external_connections SET sync_run_id=NULL,sync_lease_expires_at=NULL WHERE organization_id=? AND sync_run_id=?',
            [$org,$run]);
    }

    /** @return array<string,mixed> */
    public function status(string $org,string $conn): array
    {
        $connection=$this->connection($org,$conn);
        $run=$this->query('SELECT run_id,state,pages,observed_products,conflicts,error_code,updated_at FROM pm_sync_runs WHERE organization_id=? AND connection_id=? ORDER BY updated_at DESC LIMIT 1',
            [$org,$conn])->fetch(PDO::FETCH_ASSOC);
        $pending=$this->query("SELECT COUNT(*) FROM pm_sync_conflicts WHERE organization_id=? AND connection_id=? AND state='open'",[$org,$conn])->fetchColumn();
        $webhooks=$this->query("SELECT COUNT(*) FROM pm_webhook_inbox WHERE organization_id=? AND connection_id=? AND state='pending_reconciliation'",[$org,$conn])->fetchColumn();
        return ['shop_domain'=>$connection['shop_domain'],'mode'=>$connection['mode'],
            'last_sync_at'=>$connection['last_sync_at'],'cursor_pending'=>$connection['page_cursor']!==null,
            'last_run'=>$run===false?null:$run,'open_conflicts'=>(int)$pending,'pending_webhooks'=>(int)$webhooks];
    }

    /** Entry point only after ShopifyWebhookVerifier::verify on raw input. */
    public function recordVerifiedDelivery(string $org,string $conn,string $deliveryId,string $topic,string $rawBody,?string $resourceGid=null): bool
    {
        if ($deliveryId===''||strlen($deliveryId)>190||!preg_match('/^[a-z_]+\/[a-z_]+$/D',$topic)
            ||strlen($topic)>120||strlen($rawBody)>1048576
            ||($resourceGid!==null&&!preg_match('#^gid://shopify/Order/[1-9][0-9]{0,18}$#D',$resourceGid))
            ||(str_starts_with($topic,'orders/') !== ($resourceGid!==null))) {
            throw new DomainException('Invalid verified webhook metadata or order pointer.');
        }
        $digest=hash('sha256',$rawBody);
        $stmt=$this->query('INSERT IGNORE INTO pm_webhook_inbox (organization_id,connection_id,delivery_id,topic,payload_sha256,resource_gid) VALUES (?,?,?,?,?,?)',
            [$org,$conn,$deliveryId,$topic,$digest,$resourceGid]);
        if ($stmt->rowCount()===0) {
            $record=$this->query('SELECT payload_sha256,resource_gid,topic FROM pm_webhook_inbox WHERE organization_id=? AND connection_id=? AND delivery_id=?',[$org,$conn,$deliveryId])->fetch(PDO::FETCH_ASSOC);
            if ($record===false||!hash_equals((string)$record['payload_sha256'],$digest)
                ||$record['topic']!==$topic) {
                throw new DomainException('Webhook delivery ID reused with different payload or topic.');
            }
            // Existing, previously verified receipts may predate the nullable pointer migration.
            // Only the exact authenticated redelivery can enrich its immutable source identity.
            if ($record['resource_gid']===null && $resourceGid!==null) {
                $this->query('UPDATE pm_webhook_inbox SET resource_gid=? WHERE organization_id=? AND connection_id=? AND delivery_id=? AND payload_sha256=? AND topic=? AND resource_gid IS NULL',
                    [$resourceGid,$org,$conn,$deliveryId,$digest,$topic]);
                $record=$this->query('SELECT resource_gid FROM pm_webhook_inbox WHERE organization_id=? AND connection_id=? AND delivery_id=?',
                    [$org,$conn,$deliveryId])->fetch(PDO::FETCH_ASSOC);
            }
            if ($record===false||$record['resource_gid']!==$resourceGid) throw new DomainException('Webhook resource identity conflict.');
            return false;
        }
        return true;
    }

    /** Bounded redacted delivery pointers, never customer payload. */
    public function pendingOrderDeliveries(string $org,string $conn):array
    {
        $this->connection($org,$conn);
        return $this->query("SELECT delivery_id,resource_gid AS external_order_id,topic FROM pm_webhook_inbox WHERE organization_id=? AND connection_id=? AND state='pending_reconciliation' AND resource_gid IS NOT NULL AND topic LIKE 'orders/%' ORDER BY received_at,delivery_id LIMIT 25",[$org,$conn])->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Keyset scan of verified pointers; pending deliveries before cursor remain eligible in the next run. */
    public function pendingOrderDeliveryPage(string $org,string $conn,?string $cursor):array
    {
        $this->connection($org,$conn);
        if($cursor!==null&&($cursor===''||strlen($cursor)>190))throw new DomainException('Invalid delivery cursor.');
        $rows=$this->query("SELECT delivery_id,resource_gid AS external_order_id,topic FROM pm_webhook_inbox WHERE organization_id=? AND connection_id=? AND state='pending_reconciliation' AND resource_gid IS NOT NULL AND topic LIKE 'orders/%'".($cursor===null?'':' AND delivery_id>?')." ORDER BY delivery_id LIMIT 26",$cursor===null?[$org,$conn]:[$org,$conn,$cursor])->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25;$rows=array_slice($rows,0,25);
        return ['deliveries'=>$rows,'next_cursor'=>$more?$rows[24]['delivery_id']:null];
    }
    public function assertOrderDelivery(string $org,string $conn,string $delivery,string $externalId):void
    {
        if($delivery===''||strlen($delivery)>190||!preg_match('#^gid://shopify/Order/[1-9][0-9]{0,18}$#D',$externalId))throw new DomainException('Invalid order delivery identity.');
        $row=$this->query("SELECT state,resource_gid FROM pm_webhook_inbox WHERE organization_id=? AND connection_id=? AND delivery_id=? AND topic LIKE 'orders/%'",[$org,$conn,$delivery])->fetch(PDO::FETCH_ASSOC);
        if($row===false||$row['resource_gid']!==$externalId||!in_array($row['state'],['pending_reconciliation','sales_order_observed'],true))throw new DomainException('Verified order delivery unavailable.');
    }
    /** Only acknowledges Sales observation, not payment, inventory, or lifecycle completion. */
    public function acknowledgeOrderDelivery(string $org,string $conn,string $delivery,string $externalId):void
    {
        if(!$this->connection->inTransaction())throw new DomainException('Order delivery acknowledgement requires native transaction.');
        $this->assertOrderDelivery($org,$conn,$delivery,$externalId);
        $this->query("UPDATE pm_webhook_inbox SET state='sales_order_observed' WHERE organization_id=? AND connection_id=? AND delivery_id=? AND resource_gid=? AND state='pending_reconciliation'",[$org,$conn,$delivery,$externalId]);
        $this->assertOrderDelivery($org,$conn,$delivery,$externalId);
    }

    /** @param list<mixed> $params */
    private function query(string $sql,array $params): \PDOStatement
    {
        $stmt=$this->connection->prepare($sql);$stmt->execute($params);return $stmt;
    }
}
