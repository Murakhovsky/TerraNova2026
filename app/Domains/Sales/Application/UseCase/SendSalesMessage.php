<?php
declare(strict_types=1);
namespace Domains\Sales\Application\UseCase;
use Domains\Sales\Application\Contract\{MessageGatewayInterface,SalesOperationRepositoryInterface};
use Domains\Sales\Application\DTO\{OperationResult,SendMessageCommand};
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\{DomainEvent,EventBus,EventMetadata};
use Kernel\Transaction\Contract\TransactionManagerInterface;
final readonly class SendSalesMessage
{
    public function __construct(private MessageGatewayInterface $gateway,private SalesOperationRepositoryInterface $operations,private EventBus $events,private TransactionManagerInterface $transactions){}
    public function execute(SendMessageCommand $command,array $metadata=[],string $actorType='SYSTEM',string $actorId='system'):OperationResult{$sent=$this->gateway->send($command);if(!$sent->successful)return $sent;return $this->transactions->transactional(function()use($command,$metadata,$actorType,$actorId,$sent){$external=$sent->externalId??$command->idempotencyKey;$id=$this->operations->recordOutboundCommunication($command->organizationId,$command->dealReference,$command->channel,$command->body,$external,$command->idempotencyKey,$metadata);if($id===null)return OperationResult::success($external,['duplicate'=>true]);$correlation=substr(hash('sha256',$command->idempotencyKey),0,32);$this->events->publish(new DomainEvent(bin2hex(random_bytes(16)),$command->organizationId,SalesEventType::MESSAGE_SENT,'deal',$command->dealReference,['communication_id'=>$id,'external_id'=>$external,'channel'=>$command->channel,'purpose'=>$metadata['purpose']??'sales_message'],new EventMetadata($correlation,null,$actorType,$actorId),new \DateTimeImmutable()));return OperationResult::success($external,['communication_id'=>$id]);});}
}
