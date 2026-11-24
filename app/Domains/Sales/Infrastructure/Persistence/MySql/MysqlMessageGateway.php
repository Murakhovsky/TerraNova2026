<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;
use Domains\Sales\Application\Contract\MessageGatewayInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Infrastructure\Platform\Persistence\ExternalReferenceStoreInterface;
use PDO;
use DomainException;
use Throwable;

/** AIDA native log only: it cannot attest any external delivery. */
final readonly class MysqlMessageGateway implements MessageGatewayInterface
{
    public function __construct(private PDO $connection,private ExternalReferenceStoreInterface $references){}
    public function send(SendMessageCommand $command):OperationResult
    {
        $owns=!$this->connection->inTransaction();
        if($owns)$this->connection->beginTransaction();
        try{
            $org=$command->organizationId;$deal=$command->dealReference;$key=$command->idempotencyKey;
            if(!ctype_digit($deal)||(int)$deal<1||(string)(int)$deal!==$deal||trim($key)===''||strlen($key)>191||trim($command->body)===''||mb_strlen($command->body)>4000)throw new DomainException('Invalid native CRM message.');
            $q=$this->connection->prepare('SELECT id FROM tn_client_cases WHERE organization_id=? AND id=? FOR UPDATE');$q->execute([$org,$deal]);
            if($q->fetchColumn()===false)throw new DomainException('Deal unavailable.');
            $reference='action:'.$key;
            $id=$this->references->find($org,'aida','message',$reference);
            $title='Outbound via '.$command->channel;
            if($id!==null){
                $q=$this->connection->prepare('SELECT title,body FROM tn_client_case_activities WHERE organization_id=? AND client_case_id=? AND id=? AND activity_type="message" FOR UPDATE');
                $q->execute([$org,$deal,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if(!is_array($row)||$row['title']!==$title||$row['body']!==$command->body)throw new DomainException('Native CRM message reference conflict.');
                if($owns)$this->connection->commit();
                return OperationResult::success($id,['duplicate'=>true,'channel'=>$command->channel,'delivery_state'=>'local_recorded','recipient_delivery_verified'=>false]);
            }
            $receipts=new MysqlSalesMutationReceiptRepository($this->connection);$claim=hash('sha256',$key);$pending='pending:'.bin2hex(random_bytes(16));
            if(!$receipts->claim($org,'native.message.record',$claim,$pending))throw new DomainException('Message receipt already claimed; reconcile before retry.');
            $q=$this->connection->prepare('INSERT INTO tn_client_case_activities(organization_id,client_case_id,activity_type,title,body,completed_at) VALUES (?,?,"message",?,?,NOW())');
            $q->execute([$org,$deal,$title,$command->body]);$id=(string)$this->connection->lastInsertId();
            $this->references->put($org,'aida','message',$id,$reference);
            if(!$receipts->complete($org,'native.message.record',$claim,$pending,$id))throw new \RuntimeException('Message receipt completion failed.');
            if($owns)$this->connection->commit();
            return OperationResult::success($id,['channel'=>$command->channel,'delivery_state'=>'local_recorded','recipient_delivery_verified'=>false]);
        }catch(Throwable $e){
            if(!$owns)throw $e;
            if($this->connection->inTransaction())$this->connection->rollBack();
            return OperationResult::failure('Native CRM message recording rejected.');
        }
    }
}
