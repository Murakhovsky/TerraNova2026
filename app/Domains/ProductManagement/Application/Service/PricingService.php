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
    public function set(string $org, string $variantId, string $currency, string $amount, string $key, string $actor, string $correlation): array
    {
        $amount=ProductRules::money($amount);
        $currency=$this->currency($currency);
        if ($org===''||$variantId===''||$key===''||$actor===''||$correlation===''
            ||strlen($org)>190||strlen($key)>191||strlen($actor)>190||strlen($correlation)>191) {
            throw new DomainException('Trusted tenant, variant, actor and idempotency required.');
        }
        $hash=hash('sha256',json_encode([$variantId,$currency,$amount],JSON_THROW_ON_ERROR));
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
            $variant=$this->query('SELECT variant_id FROM pm_variants WHERE organization_id=? AND variant_id=?',[$org,$variantId])->fetch(PDO::FETCH_ASSOC);
            if ($variant===false) throw new DomainException('Unknown variant in tenant.');
            $old=$this->query('SELECT amount,revision FROM pm_base_prices WHERE organization_id=? AND variant_id=? AND currency=? FOR UPDATE',[$org,$variantId,$currency])->fetch(PDO::FETCH_ASSOC);
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
                ['currency'=>$currency,'amount'=>$amount,'revision'=>$result['revision']],new EventMetadata($correlation,null,'HUMAN',$actor),
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
