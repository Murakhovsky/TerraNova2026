<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;
use Domains\Sales\Application\Contract\SalesOperationRepositoryInterface;
use PDO;
use RuntimeException;
final readonly class MysqlSalesOperationRepository implements SalesOperationRepositoryInterface
{
    public function __construct(private PDO $connection){}
    public function recordOutboundCommunication(string $organizationId,string $dealId,string $channel,string $body,string $externalId,string $idempotencyKey,array $metadata=[]):?string
    {
        $existing=$this->receipt($organizationId,'message',$idempotencyKey);if($existing!==null)return null;$deal=$this->deal($organizationId,$dealId);if($deal===null)throw new RuntimeException('Deal was not found in the current organization.');$id=bin2hex(random_bytes(16));$statement=$this->connection->prepare('INSERT IGNORE INTO sales_communications(id,organization_id,deal_id,person_id,channel,direction,sender,recipient,body,external_id,metadata,occurred_at) VALUES(:id,:org,:deal,:person,:channel,"OUTBOUND",:sender,:recipient,:body,:external,:metadata,NOW())');$statement->execute(['id'=>$id,'org'=>$organizationId,'deal'=>$dealId,'person'=>$deal['person_id'],'channel'=>$this->channel($channel),'sender'=>'COS','recipient'=>(string)$deal['person_id'],'body'=>$body,'external'=>$externalId,'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR)]);if($statement->rowCount()!==1)return null;$this->saveReceipt($organizationId,'message',$idempotencyKey,$id);$this->touch($organizationId,$dealId);return $id;
    }
    public function recordInboundCommunication(string $organizationId,string $dealId,string $channel,string $sender,string $recipient,string $body,string $externalId,array $metadata=[]):?string
    {
        $channel=$this->channel($channel);$deal=$this->deal($organizationId,$dealId);if($deal===null)throw new RuntimeException('Deal was not found in the current organization.');$id=bin2hex(random_bytes(16));$statement=$this->connection->prepare('INSERT IGNORE INTO sales_communications(id,organization_id,deal_id,person_id,channel,direction,sender,recipient,body,external_id,metadata,occurred_at) VALUES(:id,:org,:deal,:person,:channel,"INBOUND",:sender,:recipient,:body,:external,:metadata,NOW())');$statement->execute(['id'=>$id,'org'=>$organizationId,'deal'=>$dealId,'person'=>$deal['person_id'],'channel'=>$channel,'sender'=>$sender,'recipient'=>$recipient,'body'=>$body,'external'=>$externalId,'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR)]);if($statement->rowCount()!==1)return null;$this->touch($organizationId,$dealId);return $id;
    }
    public function scheduleMeeting(string $organizationId,string $dealId,string $title,\DateTimeImmutable $scheduledAt,string $idempotencyKey,array $metadata=[]):?string
    {
        if($this->receipt($organizationId,'meeting',$idempotencyKey)!==null)return null;$deal=$this->deal($organizationId,$dealId);if($deal===null)throw new RuntimeException('Deal was not found in the current organization.');$statement=$this->connection->prepare('INSERT INTO tn_client_case_activities(organization_id,client_case_id,person_id,user_id,activity_type,title,body,due_at,completed_at) VALUES(:org,:deal,:person,:owner,"meeting",:title,:body,:due,NULL)');$statement->execute(['org'=>$organizationId,'deal'=>$dealId,'person'=>$deal['person_id'],'owner'=>$deal['assigned_user_id'],'title'=>$title,'body'=>json_encode($metadata,JSON_THROW_ON_ERROR),'due'=>$scheduledAt->format('Y-m-d H:i:s')]);$id=(string)$this->connection->lastInsertId();$this->saveReceipt($organizationId,'meeting',$idempotencyKey,$id);return $id;
    }
    private function deal(string $org,string $id):?array{$s=$this->connection->prepare('SELECT person_id,assigned_user_id FROM tn_client_cases WHERE id=:id AND organization_id=:org');$s->execute(['id'=>$id,'org'=>$org]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    private function receipt(string $org,string $type,string $key):?string{$s=$this->connection->prepare('SELECT mutation_id FROM sales_operation_receipts WHERE organization_id=:org AND operation_type=:type AND idempotency_key=:key');$s->execute(compact('org','type','key'));$v=$s->fetchColumn();return $v===false?null:(string)$v;}
    private function saveReceipt(string $org,string $type,string $key,string $id):void{$s=$this->connection->prepare('INSERT INTO sales_operation_receipts(organization_id,operation_type,idempotency_key,mutation_id) VALUES(:org,:type,:key,:id)');$s->execute(compact('org','type','key','id'));}
    private function touch(string $org,string $id):void{$s=$this->connection->prepare('UPDATE tn_client_cases SET last_activity_at=NOW() WHERE id=:id AND organization_id=:org');$s->execute(compact('org','id'));}
    private function channel(string $channel):string{$channel=strtoupper(trim($channel));return in_array($channel,['TELEGRAM','EMAIL','PHONE','WEB','WHATSAPP','VIBER'],true)?$channel:'WEB';}
}
