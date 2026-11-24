<?php
declare(strict_types=1);
namespace App\Infrastructure\Finance;
use DomainException;
use Domains\Finance\Application\Contract\FinancePaidSettlementSourcePort;
use Domains\Marketing\Application\Service\MarketingPaidOperationsReadService;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Platform\Documents\Contract\DocumentsRepositoryInterface;
use Platform\Storage\Contract\FileStorageInterface;
use PDO;

/** Integration composition only; no Finance writes and no SQL across owner boundaries. */
final readonly class NativePaidSettlementSourceAdapter implements FinancePaidSettlementSourcePort
{
    public function __construct(private MarketingPaidOperationsReadService $marketing,private DocumentsRepositoryInterface $documents,
        private FileStorageInterface $storage,private ActiveModuleResolver $modules,private PDO $connection){}
    public function marketing(TenantContext $actor,string $planId,string $snapshotRef):array
    {return $this->marketing->settlementSource($actor,$planId,$snapshotRef);}
    public function document(TenantContext $actor,string $documentRef):array
    {
        $org=$actor->organizationId()->value();
        if(!$this->modules->isEnabled($org,'documents')||!preg_match('/^DOC-[A-Za-z0-9_-]{8,76}$/D',$documentRef))
            throw new DomainException('Active Documents module and native document required.');
        if($this->connection->inTransaction()&&$this->documents->lockDocumentStatus($org,$documentRef)!=='active')
            throw new DomainException('Document is not active.');
        $d=$this->documents->view($org,$documentRef);
        $version=$d['versions'][0]??null;$key=$d['current_storage_key']??null;
        if(($d['status']??null)!=='active'||!is_array($version)||($version['version_id']??null)!==$d['current_version_id']
            ||($version['source_type']??null)!=='upload'||!is_string($key)||!str_starts_with($key,'documents/'.substr(hash('sha256',$org),0,20).'/')
            ||($d['current_mime_type']??null)!=='application/pdf'||(int)$d['current_size_bytes']<1||(int)$d['current_size_bytes']>10485760
            ||!is_string($d['current_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$d['current_sha256']))
            throw new DomainException('Current uploaded PDF provider document required.');
        $bytes=$this->storage->read($key);
        if(strlen($bytes)!==(int)$d['current_size_bytes']||!str_starts_with($bytes,'%PDF-')||!hash_equals($d['current_sha256'],hash('sha256',$bytes)))
            throw new DomainException('Provider document bytes differ from Documents checksum.');
        return ['document_ref'=>$documentRef,'version_ref'=>$d['current_version_id'],'sha256'=>$d['current_sha256'],
            'uploader_id'=>(string)$version['created_by'],'creator_id'=>(string)$d['created_by']];
    }
}
