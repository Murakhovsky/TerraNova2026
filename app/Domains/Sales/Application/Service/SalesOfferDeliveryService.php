<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\Sales\Application\Contract\SalesOfferDeliveryRepositoryInterface;
use Domains\Sales\Application\Contract\DealStageRepositoryInterface;
use Domains\Sales\Application\Contract\SalesOfferReferencePort;
use Domains\Sales\Application\Support\SalesOfferSnapshot;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Platform\Notification\Contract\NotificationDispatcherInterface;
use Platform\Notification\Contract\NotificationHandoffReadPort;
use Platform\Notification\Model\Notification;
use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Recipient;

final readonly class SalesOfferDeliveryService
{
    public function __construct(private SalesOfferService $offers,private SalesOfferDeliveryRepositoryInterface $deliveries,
        private DealStageRepositoryInterface $deals,private SalesOfferReferencePort $references,
        private NotificationDispatcherInterface $notifications,private NotificationHandoffReadPort $handoff,
        private TransactionManagerInterface $transactions,private EventBus $events){}
    public function document(string $org,string $id):array
    {
        $offer=$this->offers->read($org,$id);$doc=SalesOfferDocument::render($offer);
        $recipient=$this->deliveries->recipient($org,$offer['deal_id']);
        return [...$doc,'recipient_hash'=>$this->recipientHash($recipient)];
    }
    public function queue(Action $action):array
    {
        $org=$action->organizationId;$id=$action->targetId;$in=$action->parameters;
        if($action->targetType!=='offer'||$id===null||preg_match('/^[a-f0-9]{32}$/D',$id)!==1||$action->status!==ActionStatus::Running||$action->executionMode!=='APPROVAL_REQUIRED'||$action->idempotencyKey===null||trim($action->idempotencyKey)==='')throw new DomainException('Approved running offer Action required.');
        if(array_diff(array_keys($in),['expected_revision','terms_hash','document_hash','recipient_hash'])!==[]||!is_int($in['expected_revision']??null)||$in['expected_revision']<1)throw new DomainException('Invalid offer delivery specification.');
        foreach(['terms_hash','document_hash','recipient_hash'] as $field)if(!is_string($in[$field]??null)||preg_match('/^[a-f0-9]{64}$/D',$in[$field])!==1)throw new DomainException('Invalid approved evidence hash.');
        $first=$this->offers->read($org,$id);
        return $this->transactions->transactional(function()use($org,$id,$in,$first,$action):array{
            $deal=$this->deals->getForStageChange($org,(string)$first['deal_id']);
            if($deal===null)throw new DomainException('Deal unavailable.');
            $offer=$this->offers->read($org,$id,$first['deal_id']);
            $doc=SalesOfferDocument::render($offer);
            $recipient=$this->deliveries->recipient($org,$offer['deal_id']);$recipientHash=$this->recipientHash($recipient);
            $saved=$this->deliveries->get($org,$id,$offer['terms_version']);
            if($saved!==null){
                if($saved['recipient_hash']!==$in['recipient_hash']||$saved['document_hash']!==$in['document_hash']||$offer['terms_hash']!==$in['terms_hash'])throw new DomainException('Existing delivery evidence differs; revise quote before new delivery.');
                return $this->status($org,$id,$offer['terms_version']);
            }
            if(preg_match('/[\r\n]/',$doc['subject'])===1)throw new DomainException('Unsafe offer subject.');
            if($offer['revision']!==$in['expected_revision']||$offer['terms_hash']!==$in['terms_hash']||$doc['document_hash']!==$in['document_hash']||$recipientHash!==$in['recipient_hash'])throw new DomainException('Offer, document or recipient changed after approval.');
            if(in_array($deal['status'],['closed','lost'],true)||!in_array($offer['status'],['draft','presented'],true)||$offer['valid_until']<=gmdate('Y-m-d H:i:s'))throw new DomainException('Offer is no longer deliverable.');
            foreach($offer['lines'] as $line){
                $fresh=$this->references->resolve($org,$line);
                if($fresh===null||SalesOfferSnapshot::digest($fresh)!==SalesOfferSnapshot::digest($line['reference_snapshot']))throw new DomainException('Quoted source changed; revise and approve again.');
            }
            $notification='SQUO-'.substr(hash('sha256',$org.':'.$id.':'.$offer['terms_version']),0,40);
            $delivery=$this->notifications->send(new Notification($notification,OrganizationId::fromString($org),Channel::EMAIL,'sales.offer.email',
                new Recipient((string)$recipient['email'],(string)$recipient['full_name']),['subject'=>$doc['subject'],'body'=>$doc['text']],'en',$notification,new DateTimeImmutable(),
                ['offer_id'=>$id,'terms_version'=>$offer['terms_version'],'terms_hash'=>$offer['terms_hash'],'document_hash'=>$doc['document_hash']]));
            if($delivery->notificationId!==$notification||!in_array($delivery->status->value,['queued','sent'],true))throw new DomainException('Offer notification not accepted by transport.');
            $this->deliveries->record($org,$id,$offer['terms_version'],$notification,$recipientHash,$doc['document_hash'],$action->id);
            $result=$this->status($org,$id,$offer['terms_version']);
            if(!in_array($result['state'],['queued','processing','handed_off'],true))throw new DomainException('Durable notification outbox evidence missing.');
            $this->events->publish(new DomainEvent(bin2hex(random_bytes(16)),$org,'sales.offer.delivery.queued','offer',$id,
                ['terms_version'=>$offer['terms_version'],'notification_id'=>$notification,'document_hash'=>$doc['document_hash']],
                new EventMetadata($action->correlationId?:$action->id,null,$action->sourceType,$action->sourceId),new DateTimeImmutable()));
            return $result;
        });
    }
    public function status(string $org,string $id,int $version):array
    {
        $this->offers->read($org,$id);
        $row=$this->deliveries->get($org,$id,$version);
        if($row===null)return ['offer_id'=>$id,'terms_version'=>$version,'notification_id'=>null,'state'=>'not_queued','recipient_delivery_verified'=>false,'attempts'=>0,'handoff_at'=>null];
        $native=$this->handoff->status($org,$row['notification_id']);
        return ['offer_id'=>$id,'terms_version'=>$version,'notification_id'=>$row['notification_id'],'state'=>$native['state']??'unknown',
            'recipient_delivery_verified'=>false,'attempts'=>$native['attempts']??0,'handoff_at'=>$native['handoff_at']??null];
    }
    private function recipientHash(?array $row):?string
    {
        if($row===null||filter_var($row['email']??'',FILTER_VALIDATE_EMAIL)===false)return null;
        return hash('sha256',json_encode([(string)$row['id'],(string)$row['email'],(string)$row['full_name']],JSON_THROW_ON_ERROR));
    }
}
