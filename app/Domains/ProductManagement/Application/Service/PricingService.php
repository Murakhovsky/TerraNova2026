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
            $this->lockPriceableVariant($org,$variantId);
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

    /** Create an explicit, tenant-owned single-currency price list. */
    public function createList(string $org,string $code,string $name,string $kind,string $currency,string $key,string $actor,string $correlation):array
    {
        $code=ProductRules::code($code);$name=ProductRules::name($name);$currency=$this->currency($currency);
        if(!in_array($kind,['retail','wholesale','partner'],true))throw new DomainException('Invalid price list kind.');
        return $this->listMutation($org,'price.list.create',[$code,$name,$kind,$currency],$key,$actor,$correlation,
            'product_management.price_list.created',function()use($org,$code,$name,$kind,$currency):array{
                $id=bin2hex(random_bytes(16));
                $this->query('INSERT INTO pm_price_lists (organization_id,list_id,code,name,kind,currency) VALUES (?,?,?,?,?,?)',[$org,$id,$code,$name,$kind,$currency]);
                return ['list_id'=>$id,'code'=>$code,'name'=>$name,'kind'=>$kind,'currency'=>$currency];
            });
    }

    public function searchLists(string $org,?string $code=null,int $limit=30,int $offset=0):array
    {
        if($org===''||strlen($org)>40||$limit<1||$limit>100||$offset<0||$offset>10000000)
            throw new DomainException('Invalid price list scope or pagination.');
        $sql='SELECT list_id,code,name,kind,currency,status,revision FROM pm_price_lists WHERE organization_id=:org';
        if($code!==null)$sql.=' AND code=:code';
        $stmt=$this->connection->prepare($sql.' ORDER BY code,list_id LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$org);
        if($code!==null)$stmt->bindValue(':code',ProductRules::code($code));
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function(array $row):array{
            $row['revision']=(int)$row['revision'];return $row;
        },$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** No implicit fallback, FX conversion or inferred customer eligibility. */
    public function getListPrice(string $org,string $listId,string $variantId):array
    {
        $this->listScope($org,$listId,$variantId);
        $row=$this->query('SELECT l.currency,e.amount,e.revision FROM pm_price_lists l
            JOIN pm_variants v ON v.organization_id=l.organization_id AND v.variant_id=?
            LEFT JOIN pm_price_list_entries e ON e.organization_id=l.organization_id AND e.list_id=l.list_id AND e.variant_id=v.variant_id
            WHERE l.organization_id=? AND l.list_id=?',[$variantId,$org,$listId])->fetch(PDO::FETCH_ASSOC);
        return ['list_id'=>$listId,'variant_id'=>$variantId,'found'=>$row!==false,'currency'=>$row===false?null:$row['currency'],
            'price'=>$row===false?null:$row['amount'],'revision'=>$row===false?0:(int)($row['revision']??0)];
    }

    public function setListPrice(string $org,string $listId,string $variantId,string $amount,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        $this->listScope($org,$listId,$variantId);$amount=ProductRules::money($amount);
        if($expectedRevision<0)throw new DomainException('Invalid price list revision.');
        return $this->listMutation($org,'price.list.entry.set',[$listId,$variantId,$amount,$expectedRevision],$key,$actor,$correlation,
            'product_management.price_list.entry_updated',function()use($org,$listId,$variantId,$amount,$expectedRevision,$actor):array{
                $this->lockPriceableVariant($org,$variantId);
                $list=$this->query('SELECT currency,status FROM pm_price_lists WHERE organization_id=? AND list_id=? FOR UPDATE',[$org,$listId])->fetch(PDO::FETCH_ASSOC);
                if($list===false||$list['status']!=='active')throw new DomainException('Active tenant price list required.');
                $old=$this->query('SELECT amount,revision FROM pm_price_list_entries WHERE organization_id=? AND list_id=? AND variant_id=? FOR UPDATE',[$org,$listId,$variantId])->fetch(PDO::FETCH_ASSOC);
                if(($old===false?0:(int)$old['revision'])!==$expectedRevision)throw new DomainException('Price list entry revision conflict.');
                if($old===false){
                    $this->query('INSERT INTO pm_price_list_entries (organization_id,list_id,variant_id,amount) VALUES (?,?,?,?)',[$org,$listId,$variantId,$amount]);
                }else{
                    $this->query('UPDATE pm_price_list_entries SET amount=?,revision=revision+1 WHERE organization_id=? AND list_id=? AND variant_id=?',[$amount,$org,$listId,$variantId]);
                }
                $result=$this->getListPrice($org,$listId,$variantId);
                $this->query('INSERT INTO pm_price_list_history (organization_id,list_id,variant_id,revision,old_amount,new_amount,actor_ref) VALUES (?,?,?,?,?,?,?)',
                    [$org,$listId,$variantId,$result['revision'],$old===false?null:$old['amount'],$result['price'],$actor]);
                return $result;
            });
    }

    public function updateList(string $org,string $listId,string $name,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        return $this->changeList($org,$listId,ProductRules::name($name),$expectedRevision,$key,$actor,$correlation);
    }

    public function archiveList(string $org,string $listId,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        return $this->changeList($org,$listId,null,$expectedRevision,$key,$actor,$correlation);
    }

    private function changeList(string $org,string $listId,?string $name,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$listId)||$expectedRevision<1)throw new DomainException('Invalid price list identity or revision.');
        $archive=$name===null;
        return $this->listMutation($org,$archive?'price.list.archive':'price.list.update',[$listId,$name,$expectedRevision],$key,$actor,$correlation,
            $archive?'product_management.price_list.archived':'product_management.price_list.updated',
            function()use($org,$listId,$name,$expectedRevision,$archive):array{
                $row=$this->query('SELECT list_id,code,name,kind,currency,status,revision FROM pm_price_lists WHERE organization_id=? AND list_id=? FOR UPDATE',[$org,$listId])->fetch(PDO::FETCH_ASSOC);
                if($row===false||$row['status']!=='active'||(int)$row['revision']!==$expectedRevision)
                    throw new DomainException('Active tenant price list and current revision required.');
                $row['name']=$archive?$row['name']:$name;
                $row['status']=$archive?'archived':'active';
                $row['revision']=$expectedRevision+1;
                $this->query('UPDATE pm_price_lists SET name=?,status=?,revision=? WHERE organization_id=? AND list_id=?',
                    [$row['name'],$row['status'],$row['revision'],$org,$listId]);
                return $row;
            });
    }

    public function listHistory(string $org,string $listId,string $variantId,int $limit=30,int $offset=0):array
    {
        $this->listScope($org,$listId,$variantId);
        if($limit<1||$limit>100||$offset<0||$offset>10000000)throw new DomainException('Invalid history pagination.');
        $stmt=$this->connection->prepare('SELECT revision,old_amount,new_amount,actor_ref,created_at FROM pm_price_list_history
            WHERE organization_id=:org AND list_id=:list AND variant_id=:variant ORDER BY revision DESC LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$org);$stmt->bindValue(':list',$listId);$stmt->bindValue(':variant',$variantId);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function(array $row):array{
            $row['revision']=(int)$row['revision'];return $row;
        },$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Remove the explicit entry without destroying its identity/revision history. */
    public function removeListPrice(string $org,string $listId,string $variantId,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        $this->listScope($org,$listId,$variantId);
        if($expectedRevision<1)throw new DomainException('Invalid entry revision.');
        return $this->listMutation($org,'price.list.entry.remove',[$listId,$variantId,$expectedRevision],$key,$actor,$correlation,
            'product_management.price_list.entry_removed',function()use($org,$listId,$variantId,$expectedRevision,$actor):array{
                $this->lockPriceableVariant($org,$variantId);$this->lockActiveList($org,$listId);
                $old=$this->query('SELECT amount,revision FROM pm_price_list_entries WHERE organization_id=? AND list_id=? AND variant_id=? FOR UPDATE',[$org,$listId,$variantId])->fetch(PDO::FETCH_ASSOC);
                if($old===false||$old['amount']===null||(int)$old['revision']!==$expectedRevision)throw new DomainException('Current priced entry required.');
                $this->query('UPDATE pm_price_list_entries SET amount=NULL,revision=revision+1 WHERE organization_id=? AND list_id=? AND variant_id=?',[$org,$listId,$variantId]);
                $this->query('INSERT INTO pm_price_list_history (organization_id,list_id,variant_id,revision,old_amount,new_amount,actor_ref) VALUES (?,?,?,?,?,?,?)',[$org,$listId,$variantId,$expectedRevision+1,$old['amount'],null,$actor]);
                return $this->getListPrice($org,$listId,$variantId);
            });
    }

    /** One independently versioned quantity threshold; null amount is a tombstone. */
    public function setTier(string $org,string $listId,string $variantId,string $minimum,?string $amount,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        $this->listScope($org,$listId,$variantId);
        $minimum=ProductRules::quantity($minimum);
        [$whole,$fraction]=array_pad(explode('.',$minimum,2),2,'');$minimum=$whole.'.'.str_pad($fraction,6,'0');
        if($amount!==null)$amount=ProductRules::money($amount);
        if($expectedRevision<0||($amount===null&&$expectedRevision<1))throw new DomainException('Invalid tier revision.');
        return $this->listMutation($org,'price.list.tier.set',[$listId,$variantId,$minimum,$amount,$expectedRevision],$key,$actor,$correlation,
            'product_management.price_list.tier_changed',function()use($org,$listId,$variantId,$minimum,$amount,$expectedRevision,$actor):array{
                $this->lockPriceableVariant($org,$variantId);$list=$this->lockActiveList($org,$listId);
                $old=$this->query('SELECT amount,revision FROM pm_price_list_tiers WHERE organization_id=? AND list_id=? AND variant_id=? AND min_quantity=? FOR UPDATE',[$org,$listId,$variantId,$minimum])->fetch(PDO::FETCH_ASSOC);
                if(($old===false?0:(int)$old['revision'])!==$expectedRevision||($amount===null&&($old===false||$old['amount']===null)))
                    throw new DomainException('Tier revision or lifecycle conflict.');
                if($old===false){
                    $this->query('INSERT INTO pm_price_list_tiers (organization_id,list_id,variant_id,min_quantity,amount) VALUES (?,?,?,?,?)',[$org,$listId,$variantId,$minimum,$amount]);
                }else{
                    $this->query('UPDATE pm_price_list_tiers SET amount=?,revision=revision+1 WHERE organization_id=? AND list_id=? AND variant_id=? AND min_quantity=?',[$amount,$org,$listId,$variantId,$minimum]);
                }
                $row=$this->query('SELECT min_quantity,amount,revision FROM pm_price_list_tiers WHERE organization_id=? AND list_id=? AND variant_id=? AND min_quantity=?',[$org,$listId,$variantId,$minimum])->fetch(PDO::FETCH_ASSOC);
                $result=['list_id'=>$listId,'variant_id'=>$variantId,'currency'=>$list['currency'],'min_quantity'=>$row['min_quantity'],'price'=>$row['amount'],'revision'=>(int)$row['revision']];
                $this->query('INSERT INTO pm_price_list_tier_history (organization_id,list_id,variant_id,min_quantity,revision,old_amount,new_amount,actor_ref) VALUES (?,?,?,?,?,?,?,?)',[$org,$listId,$variantId,$minimum,$result['revision'],$old===false?null:$old['amount'],$result['price'],$actor]);
                return $result;
            });
    }

    public function tiers(string $org,string $listId,string $variantId,int $limit=30,int $offset=0,?string $minimum=null):array
    {
        $this->listScope($org,$listId,$variantId);
        if($limit<1||$limit>100||$offset<0||$offset>10000000)throw new DomainException('Invalid tier pagination.');
        $sql='SELECT t.list_id,t.variant_id,l.currency,t.min_quantity,t.amount AS price,t.revision FROM pm_price_list_tiers t
            JOIN pm_price_lists l ON l.organization_id=t.organization_id AND l.list_id=t.list_id
            WHERE t.organization_id=:org AND t.list_id=:list AND t.variant_id=:variant';
        if($minimum!==null)$sql.=' AND t.min_quantity=CAST(:min AS DECIMAL(20,6))';
        $stmt=$this->connection->prepare($sql.' ORDER BY t.min_quantity LIMIT :lim OFFSET :off');
        if($minimum!==null)$stmt->bindValue(':min',ProductRules::quantity($minimum));
        $stmt->bindValue(':org',$org);$stmt->bindValue(':list',$listId);$stmt->bindValue(':variant',$variantId);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);$stmt->execute();
        return array_map(static function(array $row):array{$row['revision']=(int)$row['revision'];return $row;},$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function tierHistory(string $org,string $listId,string $variantId,string $minimum,int $limit=30,int $offset=0):array
    {
        $this->listScope($org,$listId,$variantId);
        $minimum=ProductRules::quantity($minimum);
        if($limit<1||$limit>100||$offset<0||$offset>10000000)throw new DomainException('Invalid tier history pagination.');
        $stmt=$this->connection->prepare('SELECT min_quantity,revision,old_amount,new_amount,actor_ref,created_at FROM pm_price_list_tier_history
            WHERE organization_id=:org AND list_id=:list AND variant_id=:variant AND min_quantity=CAST(:min AS DECIMAL(20,6))
            ORDER BY revision DESC LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org',$org);$stmt->bindValue(':list',$listId);$stmt->bindValue(':variant',$variantId);$stmt->bindValue(':min',$minimum);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);$stmt->execute();
        return array_map(static function(array $row):array{$row['revision']=(int)$row['revision'];return $row;},$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Deterministic quote, not customer eligibility or a commitment to sell. */
    public function resolvePrice(string $org,string $variantId,string $currency,string $quantity,?string $listId):array
    {
        $quantity=ProductRules::quantity($quantity);$currency=$this->currency($currency);
        if($org===''||strlen($org)>40||!preg_match('/^[a-f0-9]{32}$/D',$variantId))throw new DomainException('Invalid price scope.');
        $result=['variant_id'=>$variantId,'list_id'=>$listId,'currency'=>$currency,'quantity'=>$quantity,'source'=>'none','price'=>null,'min_quantity'=>null,'revision'=>0];
        if($listId===null){
            $row=$this->query("SELECT b.amount,b.revision FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id
                JOIN pm_base_prices b ON b.organization_id=v.organization_id AND b.variant_id=v.variant_id AND b.currency=?
                WHERE v.organization_id=? AND v.variant_id=? AND v.status='active' AND p.status<>'archived'",[$currency,$org,$variantId])->fetch(PDO::FETCH_ASSOC);
            if($row!==false){$result['source']='base';$result['price']=$row['amount'];$result['revision']=(int)$row['revision'];}
            return $result;
        }
        $this->listScope($org,$listId,$variantId);
        // One statement observes list, Product/Variant status, eligible tiers and entry consistently.
        $row=$this->query("SELECT e.amount AS entry_amount,e.revision AS entry_revision,t.amount AS tier_amount,t.revision AS tier_revision,t.min_quantity
            FROM pm_price_lists l JOIN pm_variants v ON v.organization_id=l.organization_id AND v.variant_id=?
            JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id
            LEFT JOIN pm_price_list_entries e ON e.organization_id=l.organization_id AND e.list_id=l.list_id AND e.variant_id=v.variant_id
            LEFT JOIN pm_price_list_tiers t ON t.organization_id=l.organization_id AND t.list_id=l.list_id AND t.variant_id=v.variant_id AND t.amount IS NOT NULL AND t.min_quantity<=CAST(? AS DECIMAL(20,6))
            WHERE l.organization_id=? AND l.list_id=? AND l.currency=? AND l.status='active' AND v.status='active' AND p.status<>'archived'
            ORDER BY t.min_quantity DESC LIMIT 1",[$variantId,$quantity,$org,$listId,$currency])->fetch(PDO::FETCH_ASSOC);
        if($row!==false&&$row['tier_amount']!==null){$result['source']='tier';$result['price']=$row['tier_amount'];$result['min_quantity']=$row['min_quantity'];$result['revision']=(int)$row['tier_revision'];}
        elseif($row!==false&&$row['entry_amount']!==null){$result['source']='list';$result['price']=$row['entry_amount'];$result['revision']=(int)$row['entry_revision'];}
        return $result;
    }

    private function lockActiveList(string $org,string $listId):array
    {
        $list=$this->query('SELECT currency,status FROM pm_price_lists WHERE organization_id=? AND list_id=? FOR UPDATE',[$org,$listId])->fetch(PDO::FETCH_ASSOC);
        if($list===false||$list['status']!=='active')throw new DomainException('Active tenant price list required.');
        return $list;
    }

    private function listScope(string $org,string $listId,string $variantId):void
    {
        if($org===''||strlen($org)>40||!preg_match('/^[a-f0-9]{32}$/D',$listId)||!preg_match('/^[a-f0-9]{32}$/D',$variantId))
            throw new DomainException('Invalid tenant price list or Variant reference.');
    }

    private function listMutation(string $org,string $operation,array $identity,string $key,string $actor,string $correlation,string $event,callable $apply):array
    {
        if($org===''||strlen($org)>40||$key===''||strlen($key)>191||$actor===''||strlen($actor)>100||$correlation===''||strlen($correlation)>191)
            throw new DomainException('Trusted tenant, actor and receipt key required.');
        $hash=hash('sha256',json_encode([$operation,$identity],JSON_THROW_ON_ERROR));
        $this->connection->beginTransaction();
        try{
            try{
                $this->query('INSERT INTO pm_operation_receipts (organization_id,idempotency_key,operation,payload_hash) VALUES (?,?,?,?)',[$org,$key,$operation,$hash]);
            }catch(PDOException $e){
                if($e->getCode()!=='23000')throw $e;
                $old=$this->query('SELECT operation,payload_hash,result_json FROM pm_operation_receipts WHERE organization_id=? AND idempotency_key=?',[$org,$key])->fetch(PDO::FETCH_ASSOC);
                if($old===false||$old['operation']!==$operation||!hash_equals($old['payload_hash'],$hash)||$old['result_json']===null)throw new DomainException('Price list idempotency conflict.');
                $this->connection->commit();
                return json_decode($old['result_json'],true,flags:JSON_THROW_ON_ERROR);
            }
            $result=$apply();
            $this->events->append(new DomainEvent(bin2hex(random_bytes(16)),$org,$event,'PriceList',$result['list_id'],$result,
                new EventMetadata(strlen($correlation)<=40?$correlation:substr(hash('sha256',$correlation),0,40),null,'USER',$actor),new \DateTimeImmutable('now',new \DateTimeZone('UTC'))));
            $this->query('UPDATE pm_operation_receipts SET result_json=? WHERE organization_id=? AND idempotency_key=?',[json_encode($result,JSON_THROW_ON_ERROR),$org,$key]);
            $this->connection->commit();
            return $result;
        }catch(Throwable $e){
            if($this->connection->inTransaction())$this->connection->rollBack();
            throw $e;
        }
    }

    private function lockPriceableVariant(string $org,string $variantId):void
    {
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
