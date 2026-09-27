<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthSignalFeedBoundary;
use Domains\Growth\Application\Contract\GrowthSignalFeedRepositoryInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\GrowthSignalFeed;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthSignalFeedService implements GrowthSignalFeedBoundary
{
    public function __construct(
        private GrowthSignalFeedRepositoryInterface $feeds,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function createFeed(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ):array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $name=$this->input($input,'name',160);
        $url=$this->input($input,'url',1000);
        $subjectType=$this->input($input,'subject_type',80);
        $subjectId=$this->input($input,'subject_id',191);
        $signalType=$this->input($input,'signal_type',120);
        $confidence=$this->confidence($input['confidence']??0.75);
        $enabled=true;
        if(array_key_exists('enabled',$input)){
            if(!is_bool($input['enabled']))throw new InvalidArgumentException('enabled must be boolean.');
            $enabled=$input['enabled'];
        }
        $feedId='GSFD-'.$this->stableId($organizationId.':signal_feed:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(compact('name','url','subjectType','subjectId','signalType','confidence','enabled'));

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$name,$url,$subjectType,$subjectId,$signalType,
            $confidence,$enabled,$feedId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'create_signal_feed',$idempotencyKey,$fingerprint)){
                return ($this->feeds->view($organizationId,$feedId)
                    ?? throw new InvalidArgumentException('Growth signal feed receipt exists but Feed was not found.'))
                    + ['replayed'=>true];
            }
            $feed=new GrowthSignalFeed(
                $feedId,OrganizationId::fromString($organizationId),$name,$url,$subjectType,$subjectId,$signalType,$confidence,$enabled,
            );
            $this->feeds->create($feed,$actorId);
            $this->publish(GrowthEventType::SIGNAL_FEED_CREATED,$organizationId,'growth_signal_feed',$feedId,[
                'name'=>$name,'subject_type'=>$subjectType,'subject_id'=>$subjectId,
                'signal_type'=>$signalType,'confidence'=>$confidence,'enabled'=>$enabled,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.signal_feed.create','growth_signal_feed',$feedId,$idempotencyKey,[
                'url_hash'=>hash('sha256',$url),'enabled'=>$enabled,
            ]);
            return $this->feeds->view($organizationId,$feedId)
                ?? throw new InvalidArgumentException('Created Growth signal feed could not be read back.');
        });
    }

    public function setEnabled(
        string $organizationId,int $actorId,string $correlationId,string $feedId,bool $enabled,string $idempotencyKey
    ):array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $feedId=$this->bounded(trim($feedId),'feedId',80);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $operation=$enabled?'enable_signal_feed':'disable_signal_feed';
        $fingerprint=$this->fingerprint(['feed_id'=>$feedId,'enabled'=>$enabled]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$feedId,$enabled,$idempotencyKey,$operation,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return ($this->feeds->view($organizationId,$feedId)
                    ?? throw new InvalidArgumentException('Growth signal feed toggle receipt exists but Feed was not found.'))
                    + ['replayed'=>true];
            }
            $feed=$this->feeds->lock($organizationId,$feedId);
            if($enabled)$feed->enable(); else $feed->disable();
            $this->feeds->update($feed,$actorId);
            $event=$enabled?GrowthEventType::SIGNAL_FEED_ENABLED:GrowthEventType::SIGNAL_FEED_DISABLED;
            $action=$enabled?'growth.signal_feed.enable':'growth.signal_feed.disable';
            $this->publish($event,$organizationId,'growth_signal_feed',$feedId,[
                'enabled'=>$enabled,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,$action,'growth_signal_feed',$feedId,$idempotencyKey,[
                'enabled'=>$enabled,
            ]);
            return $this->feeds->view($organizationId,$feedId)
                ?? throw new InvalidArgumentException('Updated Growth signal feed could not be read back.');
        });
    }

    public function feeds(string $organizationId):array
    {
        return $this->feeds->listAll($this->bounded(trim($organizationId),'organizationId',64),200);
    }

    private function input(array $input,string $key,int $limit):string
    {
        return $this->bounded(trim((string)($input[$key]??'')),$key,$limit);
    }

    private function confidence(mixed $value):float
    {
        if(!is_int($value)&&!is_float($value))throw new InvalidArgumentException('confidence must be numeric.');
        $value=(float)$value;
        if($value<0.0||$value>1.0)throw new InvalidArgumentException('confidence must be between 0 and 1.');
        return $value;
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,int $actorId,string $correlationId
    ):void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,int $actorId,string $correlationId,string $action,string $subjectType,
        string $subjectId,string $idempotencyKey,array $data=[]
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.signal_feed','USER',(string)$actorId,
            $subjectType,$subjectId,null,['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],
            $correlationId,$this->now(),
        ));
    }

    private function stableId(string $value):string{return strtoupper(substr(hash('sha256',$value),0,20));}

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value):string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
