<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Application\Service;

use DomainException;
use Domains\ProductManagement\Domain\ProductRules;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use PDO;
use PDOException;
use Throwable;

/** One current base price per variant/currency, immutable price history. */
final readonly class PricingService
{
    public function __construct(private PDO $connection, private EventStoreInterface $events) {}

    /** @return array<string,mixed> */
    public function get(string $org, string $variantId, string $currency): array
    {
        $currency=$this->currency($currency);
        $stmt=$this->connection->prepare('SELECT amount,revision FROM pm_base_prices WHERE organization_id=? AND variant_id=? AND currency=?');
        $stmt->execute([$org,$variantId,$currency]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return ['variant_id'=>$variantId,'currency'=>$currency,'price'=>$row===false?null:$row['amount'],'revision'=>$row===false?0:(int)$row['revision']];
    }

    /** @return array<string,mixed> */
    public function set(string $org, string $variantId, string $currency, string $amount, string $key, string $actor, string $correlation, ?int $expectedRevision=null): array
    {
        $amount=ProductRules::money($amount);
        $currency=$this->currency($currency);
        if ($org===''||$variantId===''||$key===''||$actor===''||$correlation===''
            ||strlen($org)>40||strlen($key)>191||strlen($actor)>100||strlen($correlation)>191) {
            throw new DomainException('Trusted tenant, variant, actor and idempotency required.');
        }
        if ($expectedRevision!==null && $expectedRevision<0) throw new DomainException('Invalid price revision.');
        // Preserve the receipt identity for legacy callers which omit the optional fence.
        $identity=[$variantId,$currency,$amount];
        if ($expectedRevision!==null) $identity[]=$expectedRevision;
        $hash=hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
        $this->connection->beginTransaction();
        try {
            try {
                $this->query('INSERT INTO pm_operation_receipts (organization_id,idempotency_key,operation,payload_hash) VALUES (?,?,?,?)',
                    [$org,$key,'price.set',$hash]);
            } catch (PDOException $e) {
                if ($e->getCode()!=='23000') throw $e;
                $existing=$this->query('SELECT payload_hash,result_json FROM pm_operation_receipts WHERE organization_id=? AND idempotency_key=?',[$org,$key])->fetch(PDO::FETCH_ASSOC);
                if ($existing===false||!hash_equals($existing['payload_hash'],$hash)||$existing['result_json']===null) throw new DomainException('Idempotency conflict.');
                $this->connection->commit();
                return json_decode($existing['result_json'],true,flags:JSON_THROW_ON_ERROR);
            }
            // Serialize price writers and lifecycle changes using the existing native rows.
            $variant=$this->query('SELECT v.status,p.status AS product_status FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE',[$org,$variantId])->fetch(PDO::FETCH_ASSOC);
            if ($variant===false || $variant['status']!=='active' || $variant['product_status']==='archived')
                throw new DomainException('Active tenant Variant and Product required for pricing.');
            $owned=$this->query("SELECT m.canonical_id FROM pm_variants v
                INNER JOIN pm_external_mappings m
                    ON m.organization_id=v.organization_id
                    AND (
                        (m.entity_type='variant' AND m.canonical_id=v.variant_id)
                        OR (m.entity_type='product' AND m.canonical_id=v.product_id)
                    )
                INNER JOIN pm_external_connections c
                    ON c.organization_id=m.organization_id AND c.connection_id=m.connection_id
                WHERE v.organization_id=? AND v.variant_id=?
                  AND c.provider='shopify' AND c.mode='shopify_authoritative' AND c.status='ready'
                LIMIT 1",[$org,$variantId])->fetch(PDO::FETCH_ASSOC);
            if($owned!==false)
                throw new DomainException('Shopify-authoritative Price requires controlled owner cutover.');
            $old=$this->query('SELECT amount,revision FROM pm_base_prices WHERE organization_id=? AND variant_id=? AND currency=? FOR UPDATE',[$org,$variantId,$currency])->fetch(PDO::FETCH_ASSOC);
            $currentRevision=$old===false?0:(int)$old['revision'];
            if ($expectedRevision!==null && $expectedRevision!==$currentRevision)
                throw new DomainException('Base price revision conflict.');
            if ($old===false) {
                $this->query('INSERT INTO pm_base_prices (organization_id,variant_id,currency,amount) VALUES (?,?,?,?)',[$org,$variantId,$currency,$amount]);
            } else {
                $this->query('UPDATE pm_base_prices SET amount=?,revision=revision+1 WHERE organization_id=? AND variant_id=? AND currency=?',
                    [$amount,$org,$variantId,$currency]);
            }
            $priceId=bin2hex(random_bytes(16));
            $this->query('INSERT INTO pm_base_price_history (organization_id,price_event_id,variant_id,currency,old_amount,new_amount,actor_ref) VALUES (?,?,?,?,?,?,?)',
                [$org,$priceId,$variantId,$currency,$old===false?null:$old['amount'],$amount,$actor]);
            $result=$this->get($org,$variantId,$currency);
            $this->events->append(new DomainEvent(bin2hex(random_bytes(16)),$org,'product_management.price.updated','Price',$variantId,
                ['currency'=>$currency,'amount'=>$amount,'revision'=>$result['revision']],new EventMetadata(strlen($correlation)<=40?$correlation:substr(hash('sha256',$correlation),0,40),null,'USER',$actor),
                new \DateTimeImmutable('now',new \DateTimeZone('UTC'))));
            $this->query('UPDATE pm_operation_receipts SET result_json=? WHERE organization_id=? AND idempotency_key=?',
                [json_encode($result,JSON_THROW_ON_ERROR),$org,$key]);
            $this->connection->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(string $org,string $variantId,string $currency,int $limit=30,int $offset=0):array
    {
        $currency=$this->currency($currency);
        if ($org===''||strlen($org)>40||!preg_match('/^[a-f0-9]{32}$/D',$variantId)
            ||$limit<1||$limit>100||$offset<0||$offset>10000000)
            throw new DomainException('Invalid price history scope or pagination.');
        $stmt=$this->connection->prepare('SELECT price_event_id,old_amount,new_amount,actor_ref,created_at FROM pm_base_price_history
            WHERE organization_id=:org AND variant_id=:variant AND currency=:currency
            ORDER BY created_at DESC,price_event_id DESC LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$org);
        $stmt->bindValue(':variant',$variantId);
        $stmt->bindValue(':currency',$currency);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function currency(string $currency): string
    {
        $currency=strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/D',$currency)) throw new DomainException('ISO currency required.');
        return $currency;
    }

    /** @param list<mixed> $parameters */
    private function query(string $sql,array $parameters): \PDOStatement
    {
        $stmt=$this->connection->prepare($sql);
        $stmt->execute($parameters);
        return $stmt;
    }
}
