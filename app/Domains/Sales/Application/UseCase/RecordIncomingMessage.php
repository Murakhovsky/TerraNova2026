<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\SalesOperationRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class RecordIncomingMessage
{
    public function __construct(private SalesOperationRepositoryInterface $operations,private EventBus $events,private TransactionManagerInterface $transactions) {}
    public function execute(string $organizationId,string $dealId,string $channel,string $sender,string $recipient,string $body,string $externalId,string $correlationId,array $metadata=[]):OperationResult
    {
        if(trim($externalId)===''||trim($body)==='')return OperationResult::failure('externalId and body are required.');
        return $this->transactions->transactional(function()use($organizationId,$dealId,$channel,$sender,$recipient,$body,$externalId,$correlationId,$metadata){$id=$this->operations->recordInboundCommunication($organizationId,$dealId,$channel,$sender,$recipient,$body,$externalId,$metadata);if($id===null)return OperationResult::success(null,['duplicate'=>true]);$this->events->publish(new DomainEvent(bin2hex(random_bytes(16)),$organizationId,SalesEventType::MESSAGE_RECEIVED,'deal',$dealId,['communication_id'=>$id,'channel'=>strtoupper($channel),'external_id'=>$externalId],new EventMetadata($correlationId,null,'INTEGRATION',$channel),new \DateTimeImmutable()));return OperationResult::success($id,['duplicate'=>false]);});
    }
}
