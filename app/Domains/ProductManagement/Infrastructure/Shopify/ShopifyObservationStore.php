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

    /** Explicit operator-only provisioning, never from a webhook payload. */
    public function provision(string $org,string $shopDomain,string $secretRef): string
    {
        ShopifyReadClient::assertShopDomain($shopDomain);
        if ($org===''||strlen($org)>190||$secretRef!=='shopify_env_primary') throw new DomainException('Unsupported connection provisioning.');
        $id=bin2hex(random_bytes(16));
        $this->query('INSERT INTO pm_external_connections (organization_id,connection_id,provider,shop_domain,secret_ref,status) VALUES (?,?,?,?,?,?)',
            [$org,$id,'shopify',$shopDomain,$secretRef,'ready']);
        return $id;
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
    public function observeProduct(string $org,string $conn,string $gid,array $snapshot): void
    {
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        $this->query('INSERT INTO pm_external_product_snapshots (organization_id,connection_id,product_gid,payload_hash,payload_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE payload_hash=VALUES(payload_hash),payload_json=VALUES(payload_json),observed_at=NOW(6)',
            [$org,$conn,$gid,hash('sha256',$json),$json]);
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
        $this->query('INSERT INTO pm_sync_conflicts (organization_id,conflict_id,connection_id,external_gid,reason,detail_json) VALUES (?,?,?,?,?,?)',
            [$org,bin2hex(random_bytes(16)),$conn,mb_substr($gid,0,191),mb_substr($reason,0,80),json_encode($details,JSON_THROW_ON_ERROR)]);
    }

    public function start(string $org,string $conn): string
    {
        $id=bin2hex(random_bytes(16));
        $this->query('INSERT INTO pm_sync_runs (organization_id,run_id,connection_id) VALUES (?,?,?)',[$org,$id,$conn]);
        return $id;
    }

    public function checkpoint(string $org,string $conn,string $run,string $cursor,int $count,int $conflicts,bool $finished): void
    {
        $this->query('UPDATE pm_sync_runs SET pages=pages+1,observed_products=observed_products+?,conflicts=conflicts+?,cursor=?,state=? WHERE organization_id=? AND connection_id=? AND run_id=?',
            [$count,$conflicts,$cursor,$finished?'completed':'paused',$org,$conn,$run]);
        $this->query('UPDATE pm_external_connections SET cursor=?,last_sync_at=COALESCE(?,last_sync_at) WHERE organization_id=? AND connection_id=?',
            [$finished?null:$cursor,$finished?gmdate('Y-m-d H:i:s'):null,$org,$conn]);
    }

    public function fail(string $org,string $run,string $code): void
    {
        $this->query("UPDATE pm_sync_runs SET state='error',error_code=? WHERE organization_id=? AND run_id=?",
            [mb_substr($code,0,190),$org,$run]);
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
            'last_sync_at'=>$connection['last_sync_at'],'cursor_pending'=>$connection['cursor']!==null,
            'last_run'=>$run===false?null:$run,'open_conflicts'=>(int)$pending,'pending_webhooks'=>(int)$webhooks];
    }

    /** Entry point only after ShopifyWebhookVerifier::verify on raw input. */
    public function recordVerifiedDelivery(string $org,string $conn,string $deliveryId,string $topic,string $rawBody): bool
    {
        if ($deliveryId===''||strlen($deliveryId)>190||!preg_match('/^[a-z_]+\/[a-z_]+$/D',$topic)
            ||strlen($topic)>120||strlen($rawBody)>1048576) throw new DomainException('Invalid webhook metadata.');
        $digest=hash('sha256',$rawBody);
        $stmt=$this->query('INSERT IGNORE INTO pm_webhook_inbox (organization_id,connection_id,delivery_id,topic,payload_sha256) VALUES (?,?,?,?,?)',
            [$org,$conn,$deliveryId,$topic,$digest]);
        if ($stmt->rowCount()===0) {
            $record=$this->query('SELECT payload_sha256 FROM pm_webhook_inbox WHERE organization_id=? AND connection_id=? AND delivery_id=?',[$org,$conn,$deliveryId])->fetch(PDO::FETCH_ASSOC);
            if ($record===false||!hash_equals((string)$record['payload_sha256'],$digest)) throw new DomainException('Webhook delivery ID reused with different payload.');
            return false;
        }
        return true;
    }

    /** @param list<mixed> $params */
    private function query(string $sql,array $params): \PDOStatement
    {
        $stmt=$this->connection->prepare($sql);$stmt->execute($params);return $stmt;
    }
}
