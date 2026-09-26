<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthJsonSignalSourceBoundary;
use Domains\Growth\Application\Contract\GrowthJsonSignalSourceRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\GrowthJsonSignalSource;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthJsonSignalSourceService implements GrowthJsonSignalSourceBoundary
{
    public function __construct(
        private GrowthJsonSignalSourceRepositoryInterface $sources,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function createSource(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ):array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $name=$this->input($input,'name',160);
        $url=$this->input($input,'url',1000);
        $authMode=$this->input($input,'auth_mode',40);
        $credentialReference=$this->input($input,'credential_reference',500);
        $apiKeyHeader=null;
        if(array_key_exists('api_key_header',$input)&&$input['api_key_header']!==null){
            if(!is_string($input['api_key_header']))throw new InvalidArgumentException('api_key_header must be a string or null.');
            $apiKeyHeader=trim($input['api_key_header']);
            if($apiKeyHeader==='')$apiKeyHeader=null;
        }
        $subjectType=$this->input($input,'subject_type',80);
        $subjectId=$this->input($input,'subject_id',191);
        $signalType=$this->input($input,'signal_type',120);
        $confidence=$this->confidence($input['confidence']??0.75);
        $enabled=$input['enabled']??true;
        if(!is_bool($enabled))throw new InvalidArgumentException('enabled must be boolean.');

        $sourceId='GSJS-'.$this->stableId($organizationId.':json_signal_source:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'name'=>$name,'url'=>$url,'auth_mode'=>$authMode,
            'credential_reference_hash'=>hash('sha256',$credentialReference),'api_key_header'=>$apiKeyHeader,
            'subject_type'=>$subjectType,'subject_id'=>$subjectId,'signal_type'=>$signalType,
            'confidence'=>$confidence,'enabled'=>$enabled,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$sourceId,$fingerprint,
            $name,$url,$authMode,$credentialReference,$apiKeyHeader,$subjectType,$subjectId,$signalType,$confidence,$enabled
        ):array{
            if(!$this->receipts->claim($organizationId,'create_json_signal_source',$idempotencyKey,$fingerprint)){
                $row=$this->sources->view($organizationId,$sourceId)
                    ?? throw new InvalidArgumentException('Growth JSON source receipt exists but source was not found.');
                return $this->publicView($row)+['replayed'=>true];
            }
            $source=new GrowthJsonSignalSource(
                $sourceId,OrganizationId::fromString($organizationId),$name,$url,$authMode,$credentialReference,$apiKeyHeader,
                $subjectType,$subjectId,$signalType,$confidence,$enabled,
            );
            $this->sources->create($source,$actorId);
            $this->publish(GrowthEventType::SIGNAL_JSON_SOURCE_CREATED,$organizationId,'growth_json_signal_source',$sourceId,[
                'name'=>$name,'auth_mode'=>$authMode,'api_key_header'=>$apiKeyHeader,
                'subject_type'=>$subjectType,'subject_id'=>$subjectId,'signal_type'=>$signalType,
                'confidence'=>$confidence,'enabled'=>$enabled,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.json_signal_source.create','growth_json_signal_source',
                $sourceId,$idempotencyKey,[
                    'url_hash'=>hash('sha256',$url),
                    'credential_reference_hash'=>hash('sha256',$credentialReference),
                    'auth_mode'=>$authMode,'enabled'=>$enabled,
                ],
            );
            $row=$this->sources->view($organizationId,$sourceId)
                ?? throw new InvalidArgumentException('Created Growth JSON signal source could not be read back.');
            return $this->publicView($row);
        });
    }

    public function setEnabled(
        string $organizationId,int $actorId,string $correlationId,string $sourceId,bool $enabled,string $idempotencyKey
    ):array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $sourceId=$this->bounded(trim($sourceId),'sourceId',80);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $operation=$enabled?'enable_json_signal_source':'disable_json_signal_source';
        $fingerprint=$this->fingerprint(['source_id'=>$sourceId,'enabled'=>$enabled]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$sourceId,$enabled,$idempotencyKey,$operation,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                $row=$this->sources->view($organizationId,$sourceId)
                    ?? throw new InvalidArgumentException('Growth JSON source toggle receipt exists but source was not found.');
                return $this->publicView($row)+['replayed'=>true];
            }
            $source=$this->sources->lock($organizationId,$sourceId);
            if($enabled)$source->enable(); else $source->disable();
            $this->sources->update($source,$actorId);
            $event=$enabled?GrowthEventType::SIGNAL_JSON_SOURCE_ENABLED:GrowthEventType::SIGNAL_JSON_SOURCE_DISABLED;
            $action=$enabled?'growth.json_signal_source.enable':'growth.json_signal_source.disable';
            $this->publish($event,$organizationId,'growth_json_signal_source',$sourceId,['enabled'=>$enabled],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,$action,'growth_json_signal_source',$sourceId,$idempotencyKey,['enabled'=>$enabled],
            );
            $row=$this->sources->view($organizationId,$sourceId)
                ?? throw new InvalidArgumentException('Updated Growth JSON signal source could not be read back.');
            return $this->publicView($row);
        });
    }

    public function sources(string $organizationId):array
    {
        return array_map(
            fn(array $row):array=>$this->publicView($row),
            $this->sources->listAll($this->bounded(trim($organizationId),'organizationId',64),200),
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicView(array $row):array
    {
        $reference=(string)($row['credential_reference']??'');
        unset($row['credential_reference']);
        $row['credential_configured']=$reference!=='';
        $row['credential_reference_hash']=$reference===''?null:substr(hash('sha256',$reference),0,16);
        return $row;
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
            bin2hex(random_bytes(16)),$organizationId,'growth.json_signal_source','USER',(string)$actorId,
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
