<?php
declare(strict_types=1);

namespace Platform\Documents\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Platform\Documents\Contract\DocumentAttachmentPort;
use Platform\Documents\Contract\DocumentMutationReceiptInterface;
use Platform\Documents\Contract\DocumentsRepositoryInterface;
use Platform\Documents\Event\DocumentsEventType;
use Platform\Storage\Contract\FileStorageInterface;
use Throwable;

final readonly class DocumentsRuntimeService implements DocumentAttachmentPort
{
    private const MAX_CONTENT_BYTES = 10_485_760;

    public function __construct(
        private DocumentsRepositoryInterface $documents,
        private DocumentMutationReceiptInterface $receipts,
        private FileStorageInterface $storage,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function upload(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $idempotencyKey,
        array $input,
    ): array {
        $title=$this->required($input,'title',220);
        $filename=$this->filename($this->required($input,'filename',191));
        $mimeType=$this->required($input,'mime_type',120);
        $contents=$this->contents($input);

        $documentId='DOC-'.$this->stableId($organizationId.':upload:'.$idempotencyKey);
        $fileId='FILE-'.$this->stableId($organizationId.':upload-file:'.$idempotencyKey);
        $versionId='VER-'.$this->stableId($organizationId.':upload-version:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'title'=>$title,'filename'=>$filename,'mime_type'=>$mimeType,'sha256'=>hash('sha256',$contents),
        ]);
        $storageKey=$this->storageKey($organizationId,$documentId,$fileId,$filename);
        $stored=false;

        try {
            $result=$this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$idempotencyKey,$title,$mimeType,$contents,
                $documentId,$fileId,$versionId,$fingerprint,$storageKey,&$stored
            ):array{
                if(!$this->receipts->claim($organizationId,'upload',$idempotencyKey,$fingerprint)){
                    return ($this->documents->view($organizationId,$documentId)
                        ?? throw new InvalidArgumentException('Document upload receipt exists but Document was not found.'))
                        + ['replayed'=>true];
                }

                $file=$this->storage->put($storageKey,$contents,$mimeType,[
                    'organization_id'=>$organizationId,'document_id'=>$documentId,
                ]);
                $stored=true;
                $this->documents->createDocument(
                    [
                        'organization_id'=>$organizationId,'document_id'=>$documentId,'title'=>$title,
                        'status'=>'active','created_by'=>$actorId,'updated_by'=>$actorId,
                    ],
                    [
                        'organization_id'=>$organizationId,'file_id'=>$fileId,'storage_key'=>$file->key,
                        'mime_type'=>$mimeType,'size_bytes'=>$file->size,'sha256'=>$file->sha256,'created_by'=>$actorId,
                    ],
                    [
                        'organization_id'=>$organizationId,'version_id'=>$versionId,'document_id'=>$documentId,
                        'version_number'=>1,'file_id'=>$fileId,'source_type'=>'upload','created_by'=>$actorId,
                    ],
                );
                $this->publish(DocumentsEventType::UPLOADED,$organizationId,$documentId,[
                    'file_id'=>$fileId,'version_id'=>$versionId,'mime_type'=>$mimeType,'sha256'=>$file->sha256,
                ],$actorId,$correlationId);
                $this->appendAudit($organizationId,$actorId,$correlationId,'documents.upload','document',$documentId,$idempotencyKey,[
                    'file_id'=>$fileId,'version_id'=>$versionId,'mime_type'=>$mimeType,'size_bytes'=>$file->size,
                ]);
                return $this->documents->view($organizationId,$documentId)
                    ?? throw new InvalidArgumentException('Uploaded Document could not be read back.');
            });
            return $result;
        } catch(Throwable $error) {
            if($stored){
                try{$this->storage->delete($storageKey);}catch(Throwable){}
            }
            throw $error;
        }
    }

    public function attachExistingDocument(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $documentId,
        string $relatedType,
        string $relatedId,
        string $idempotencyKey,
    ): array {
        $documentId=$this->nonEmpty($documentId,'documentId',80);
        $relatedType=strtolower($this->nonEmpty($relatedType,'relatedType',64));
        $relatedId=$this->nonEmpty($relatedId,'relatedId',191);
        if(!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/',$relatedType)){
            throw new InvalidArgumentException('Invalid related document type.');
        }

        $relationId='REL-'.$this->stableId($organizationId.':attach:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'document_id'=>$documentId,'related_type'=>$relatedType,'related_id'=>$relatedId,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$documentId,$relatedType,$relatedId,$relationId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'attach',$idempotencyKey,$fingerprint)){
                return ($this->documents->findRelation($organizationId,$relationId)
                    ?? throw new InvalidArgumentException('Document attachment receipt exists but relation was not found.'))
                    + ['replayed'=>true];
            }
            if($this->documents->lockDocumentStatus($organizationId,$documentId)==='archived'){
                throw new InvalidArgumentException('Archived Document cannot be attached.');
            }

            if(!$this->documents->attach($organizationId,$relationId,$documentId,$relatedType,$relatedId,$actorId)){
                throw new InvalidArgumentException('Document relation already exists.');
            }
            $relation=$this->documents->findRelation($organizationId,$relationId)
                ?? throw new InvalidArgumentException('Document relation could not be read back.');
            $this->publish(DocumentsEventType::ATTACHED,$organizationId,$documentId,[
                'relation_id'=>$relationId,'related_type'=>$relatedType,'related_id'=>$relatedId,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'documents.attach','document',$documentId,$idempotencyKey,$relation);
            return $relation;
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createVersion(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $documentId,
        string $idempotencyKey,
        array $input,
    ): array {
        $documentId=$this->nonEmpty($documentId,'documentId',80);
        $filename=$this->filename($this->required($input,'filename',191));
        $mimeType=$this->required($input,'mime_type',120);
        $contents=$this->contents($input);
        $fileId='FILE-'.$this->stableId($organizationId.':version-file:'.$idempotencyKey);
        $versionId='VER-'.$this->stableId($organizationId.':version:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'document_id'=>$documentId,'filename'=>$filename,'mime_type'=>$mimeType,'sha256'=>hash('sha256',$contents),
        ]);
        $storageKey=$this->storageKey($organizationId,$documentId,$fileId,$filename);
        $stored=false;

        try {
            return $this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$idempotencyKey,$documentId,$mimeType,$contents,
                $fileId,$versionId,$fingerprint,$storageKey,&$stored
            ):array{
                if(!$this->receipts->claim($organizationId,'version',$idempotencyKey,$fingerprint)){
                    return ($this->documents->view($organizationId,$documentId)
                        ?? throw new InvalidArgumentException('Document version receipt exists but Document was not found.'))
                        + ['replayed'=>true];
                }
                $number=$this->documents->nextVersionNumber($organizationId,$documentId);
                $file=$this->storage->put($storageKey,$contents,$mimeType,[
                    'organization_id'=>$organizationId,'document_id'=>$documentId,'version'=>$number,
                ]);
                $stored=true;
                $this->documents->createVersion(
                    $organizationId,$documentId,
                    [
                        'organization_id'=>$organizationId,'file_id'=>$fileId,'storage_key'=>$file->key,
                        'mime_type'=>$mimeType,'size_bytes'=>$file->size,'sha256'=>$file->sha256,'created_by'=>$actorId,
                    ],
                    [
                        'organization_id'=>$organizationId,'version_id'=>$versionId,'document_id'=>$documentId,
                        'version_number'=>$number,'file_id'=>$fileId,'source_type'=>'upload','created_by'=>$actorId,
                    ],
                );
                $this->publish(DocumentsEventType::VERSION_CREATED,$organizationId,$documentId,[
                    'version_id'=>$versionId,'version_number'=>$number,'file_id'=>$fileId,'sha256'=>$file->sha256,
                ],$actorId,$correlationId);
                $this->appendAudit($organizationId,$actorId,$correlationId,'documents.version.create','document',$documentId,$idempotencyKey,[
                    'version_id'=>$versionId,'version_number'=>$number,'file_id'=>$fileId,
                ]);
                return $this->documents->view($organizationId,$documentId)
                    ?? throw new InvalidArgumentException('Versioned Document could not be read back.');
            });
        } catch(Throwable $error) {
            if($stored){
                try{$this->storage->delete($storageKey);}catch(Throwable){}
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function generateFromTemplate(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $templateId,
        string $idempotencyKey,
        array $input,
    ): array {
        $templateId=$this->nonEmpty($templateId,'templateId',80);
        $variables=is_array($input['variables']??null)?$input['variables']:[];
        $title=trim((string)($input['title']??''));
        $requestedFilename=trim((string)($input['filename']??''));
        $fingerprint=$this->fingerprint([
            'template_id'=>$templateId,'title'=>$title,'filename'=>$requestedFilename,'variables'=>$variables,
        ]);
        $documentId='DOC-'.$this->stableId($organizationId.':generate:'.$idempotencyKey);
        $fileId='FILE-'.$this->stableId($organizationId.':generate-file:'.$idempotencyKey);
        $versionId='VER-'.$this->stableId($organizationId.':generate-version:'.$idempotencyKey);
        $storageKey=null;
        $stored=false;

        try {
            return $this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$idempotencyKey,$templateId,$variables,$title,$requestedFilename,
                $fingerprint,$documentId,$fileId,$versionId,&$storageKey,&$stored
            ):array{
                if(!$this->receipts->claim($organizationId,'generate',$idempotencyKey,$fingerprint)){
                    return ($this->documents->view($organizationId,$documentId)
                        ?? throw new InvalidArgumentException('Document generation receipt exists but Document was not found.'))
                        + ['replayed'=>true];
                }
                $template=$this->documents->findTemplate($organizationId,$templateId)
                    ?? throw new InvalidArgumentException('Document template was not found.');
                $documentTitle=$title!==''?$this->clip($title,220):$this->clip((string)$template['name'],220);
                $mimeType=$this->nonEmpty((string)$template['mime_type'],'template mime type',120);
                $body=$this->render((string)$template['body'],$variables);
                if(strlen($body)>self::MAX_CONTENT_BYTES){
                    throw new InvalidArgumentException('Generated Document content exceeds the 10 MiB limit.');
                }
                $filename=$requestedFilename!==''?$this->filename($requestedFilename):$this->filename(
                    $this->render((string)($template['filename_pattern']?:($template['name'].'.txt')),$variables)
                );
                $storageKey=$this->storageKey($organizationId,$documentId,$fileId,$filename);
                $file=$this->storage->put($storageKey,$body,$mimeType,[
                    'organization_id'=>$organizationId,'document_id'=>$documentId,'template_id'=>$templateId,
                ]);
                $stored=true;
                $this->documents->createDocument(
                    [
                        'organization_id'=>$organizationId,'document_id'=>$documentId,'title'=>$documentTitle,
                        'status'=>'active','created_by'=>$actorId,'updated_by'=>$actorId,
                    ],
                    [
                        'organization_id'=>$organizationId,'file_id'=>$fileId,'storage_key'=>$file->key,
                        'mime_type'=>$mimeType,'size_bytes'=>$file->size,'sha256'=>$file->sha256,'created_by'=>$actorId,
                    ],
                    [
                        'organization_id'=>$organizationId,'version_id'=>$versionId,'document_id'=>$documentId,
                        'version_number'=>1,'file_id'=>$fileId,'source_type'=>'template','created_by'=>$actorId,
                    ],
                );
                $this->publish(DocumentsEventType::GENERATED,$organizationId,$documentId,[
                    'template_id'=>$templateId,'file_id'=>$fileId,'version_id'=>$versionId,'sha256'=>$file->sha256,
                ],$actorId,$correlationId);
                $this->appendAudit($organizationId,$actorId,$correlationId,'documents.generate','document',$documentId,$idempotencyKey,[
                    'template_id'=>$templateId,'file_id'=>$fileId,'version_id'=>$versionId,
                ]);
                return $this->documents->view($organizationId,$documentId)
                    ?? throw new InvalidArgumentException('Generated Document could not be read back.');
            });
        } catch(Throwable $error) {
            if($stored&&is_string($storageKey)){
                try{$this->storage->delete($storageKey);}catch(Throwable){}
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function requestSignature(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $documentId,
        string $signerId,
        string $idempotencyKey,
    ): array {
        $documentId=$this->nonEmpty($documentId,'documentId',80);
        $signerId=$this->nonEmpty($signerId,'signerId',191);
        $signatureId='SIG-'.$this->stableId($organizationId.':signature-request:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(['document_id'=>$documentId,'signer_id'=>$signerId]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$documentId,$signerId,$idempotencyKey,$signatureId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'signature_request',$idempotencyKey,$fingerprint)){
                return ($this->documents->findSignature($organizationId,$signatureId)
                    ?? throw new InvalidArgumentException('Signature request receipt exists but Signature was not found.'))
                    + ['replayed'=>true];
            }
            if($this->documents->lockDocumentStatus($organizationId,$documentId)==='archived'){
                throw new InvalidArgumentException('Archived Document cannot request signatures.');
            }
            if(!$this->documents->createSignatureRequest($organizationId,$signatureId,$documentId,$signerId,$actorId)){
                throw new InvalidArgumentException('Document signature request already exists.');
            }
            $signature=$this->documents->findSignature($organizationId,$signatureId)
                ?? throw new InvalidArgumentException('Signature request could not be read back.');
            $this->publish(DocumentsEventType::SIGNATURE_REQUESTED,$organizationId,$documentId,[
                'signature_id'=>$signatureId,'signer_id'=>$signerId,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'documents.signature.request','document',$documentId,$idempotencyKey,[
                'signature_id'=>$signatureId,'signer_id'=>$signerId,
            ]);
            return $signature;
        });
    }

    /** @return array<string,mixed> */
    public function sign(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $signatureId,
        string $signedBy,
        string $signatureReference,
        string $idempotencyKey,
    ): array {
        $signatureId=$this->nonEmpty($signatureId,'signatureId',80);
        $signedBy=$this->nonEmpty($signedBy,'signedBy',191);
        $signatureReference=$this->nonEmpty($signatureReference,'signatureReference',255);
        $fingerprint=$this->fingerprint([
            'signature_id'=>$signatureId,'signed_by'=>$signedBy,'signature_reference'=>$signatureReference,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$signatureId,$signedBy,$signatureReference,$idempotencyKey,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'sign',$idempotencyKey,$fingerprint)){
                return ($this->documents->findSignature($organizationId,$signatureId)
                    ?? throw new InvalidArgumentException('Signature receipt exists but Signature was not found.'))
                    + ['replayed'=>true];
            }
            $existing=$this->documents->findSignature($organizationId,$signatureId)
                ?? throw new InvalidArgumentException('Signature request was not found.');
            $documentId=(string)($existing['document_id']??'');
            if($this->documents->lockDocumentStatus($organizationId,$documentId)==='archived'){
                throw new InvalidArgumentException('Archived Document cannot be signed.');
            }
            if((string)($existing['status']??'')==='signed'){
                if((string)($existing['signed_by']??'')!==$signedBy
                    ||(string)($existing['signature_reference']??'')!==$signatureReference){
                    throw new InvalidArgumentException('Signature is already completed with different evidence.');
                }
                return $existing+['replayed'=>true];
            }
            if(!$this->documents->sign($organizationId,$signatureId,$signedBy,$signatureReference,$actorId)){
                throw new InvalidArgumentException('Signature cannot be completed from its current status.');
            }
            $signature=$this->documents->findSignature($organizationId,$signatureId)
                ?? throw new InvalidArgumentException('Signed Signature could not be read back.');
            $this->publish(DocumentsEventType::SIGNED,$organizationId,$documentId,[
                'signature_id'=>$signatureId,'signer_id'=>$signature['signer_id']??null,'signed_by'=>$signedBy,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'documents.signature.sign','document',$documentId,$idempotencyKey,[
                'signature_id'=>$signatureId,'signed_by'=>$signedBy,
            ]);
            return $signature;
        });
    }

    /** @return array<string,mixed> */
    public function archive(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $documentId,
        string $idempotencyKey,
    ): array {
        $documentId=$this->nonEmpty($documentId,'documentId',80);
        $fingerprint=$this->fingerprint(['document_id'=>$documentId]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$documentId,$idempotencyKey,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'archive',$idempotencyKey,$fingerprint)){
                return ($this->documents->view($organizationId,$documentId)
                    ?? throw new InvalidArgumentException('Document archive receipt exists but Document was not found.'))
                    + ['replayed'=>true];
            }
            $before=$this->documents->view($organizationId,$documentId)
                ?? throw new InvalidArgumentException('Document was not found.');
            if((string)($before['status']??'')==='archived')return $before+['already_archived'=>true];
            if(!$this->documents->archive($organizationId,$documentId,$actorId)){
                throw new InvalidArgumentException('Document could not be archived.');
            }
            $this->publish(DocumentsEventType::ARCHIVED,$organizationId,$documentId,[],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'documents.archive','document',$documentId,$idempotencyKey);
            return $this->documents->view($organizationId,$documentId)
                ?? throw new InvalidArgumentException('Archived Document could not be read back.');
        });
    }

    /** @return array<string,mixed>|null */
    public function view(string $organizationId,string $documentId):?array
    {
        return $this->documents->view($organizationId,$this->nonEmpty($documentId,'documentId',80));
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,string $organizationId,string $documentId,array $payload,int $actorId,string $correlationId
    ):void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,'document',$documentId,$payload,
            new EventMetadata($correlationId,null,'USER',(string)$actorId),new DateTimeImmutable(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,int $actorId,string $correlationId,string $action,
        string $subjectType,string $subjectId,string $idempotencyKey,array $data=[]
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'documents.mutation','USER',(string)$actorId,
            $subjectType,$subjectId,null,[
                'action'=>$action,
                'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                'result'=>$data,
            ],$correlationId,new DateTimeImmutable(),
        ));
    }

    /** @param array<string,mixed> $input */
    private function contents(array $input):string
    {
        if(isset($input['content_base64'])){
            $decoded=base64_decode((string)$input['content_base64'],true);
            if($decoded===false)throw new InvalidArgumentException('content_base64 is invalid.');
            $contents=$decoded;
        } elseif(array_key_exists('content',$input)) {
            $contents=(string)$input['content'];
        } else {
            throw new InvalidArgumentException('Document content or content_base64 is required.');
        }
        if(strlen($contents)>self::MAX_CONTENT_BYTES)throw new InvalidArgumentException('Document content exceeds the 10 MiB limit.');
        return $contents;
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key,int $limit):string
    {
        return $this->nonEmpty((string)($input[$key]??''),$key,$limit);
    }

    private function nonEmpty(string $value,string $name,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($name.' is invalid.');
        return $value;
    }

    private function filename(string $value):string
    {
        $value=basename(str_replace('\\','/',$value));
        $value=preg_replace('/[^A-Za-z0-9._ -]+/u','_',$value)??'';
        $value=trim($value," .\t\n\r\0\x0B");
        if($value==='')throw new InvalidArgumentException('filename is invalid.');
        return $this->clip($value,191);
    }

    private function storageKey(string $organizationId,string $documentId,string $fileId,string $filename):string
    {
        return 'documents/'.substr(hash('sha256',$organizationId),0,20).'/'.$documentId.'/'.$fileId.'/'.$filename;
    }

    /** @param array<string,mixed> $variables */
    private function render(string $template,array $variables):string
    {
        $replace=[];
        foreach($variables as $key=>$value){
            if(!is_scalar($value)&&$value!==null)throw new InvalidArgumentException('Template variables must be scalar.');
            $replace['{{'.(string)$key.'}}']=$value===null?'':(string)$value;
        }
        return strtr($template,$replace);
    }

    private function stableId(string $value):string
    {
        return strtoupper(substr(hash('sha256',$value),0,20));
    }

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
        return hash('sha256',(string)json_encode(
            $normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function clip(string $value,int $limit):string
    {
        return mb_substr($value,0,$limit);
    }
}
