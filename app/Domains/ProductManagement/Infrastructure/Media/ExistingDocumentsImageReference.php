<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Media;

use DomainException;
use Domains\ProductManagement\Application\Contract\ProductMediaFileReferenceInterface;
use PDO;
use Domains\ProductManagement\Application\Contract\ProductMediaBrowserPort;
use Platform\Storage\Contract\FileStorageInterface;

/**
 * Tenant-owned COS Documents file is the only accepted local image source.
 * Unlike tn_media_assets legacy rows, these files have an organization ID.
 */
final readonly class ExistingDocumentsImageReference implements ProductMediaFileReferenceInterface, ProductMediaBrowserPort
{
    private const MIMES=['image/jpeg','image/png','image/webp','image/gif'];
    private const MAX_BYTES=15728640;

    public function __construct(private PDO $connection,private FileStorageInterface $storage){}

    public function candidates(string $organizationId,string $query,int $limit,int $offset):array
    {
        if($organizationId===''||strlen($organizationId)>64||mb_strlen($query)>100||$limit<1||$limit>30||$offset<0||$offset>30000)
            throw new DomainException('Invalid image candidate scope.');
        $sql="SELECT f.file_id,d.title,f.mime_type,f.size_bytes FROM cos_documents d
            JOIN cos_document_versions v ON v.organization_id=d.organization_id AND v.version_id=d.current_version_id AND v.document_id=d.document_id
            JOIN cos_document_files f ON f.organization_id=v.organization_id AND f.file_id=v.file_id
            WHERE d.organization_id=:org AND d.status='active' AND f.mime_type IN ('image/jpeg','image/png','image/webp','image/gif')
            AND f.size_bytes BETWEEN 1 AND 15728640 AND (LOCATE(:query,d.title)>0 OR LOCATE(:file_query,f.file_id)>0 OR :empty_query='')
            ORDER BY d.updated_at DESC,d.document_id LIMIT :lim OFFSET :off";
        $stmt=$this->connection->prepare($sql);
        foreach(['org'=>$organizationId,'query'=>$query,'file_query'=>$query,'empty_query'=>$query] as $key=>$value)$stmt->bindValue(':'.$key,$value);
        $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);$stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function preview(string $organizationId,string $fileId):array
    {
        $image=$this->readVerifiedImage($organizationId,$fileId);
        return ['mime_type'=>$image['mime_type'],'bytes'=>$image['bytes']];
    }

    public function verifiedImage(string $organizationId,string $fileId):array
    {
        $image=$this->readVerifiedImage($organizationId,$fileId);
        unset($image['bytes']);
        return $image;
    }

    private function readVerifiedImage(string $organizationId,string $fileId):array
    {
        if($organizationId===''||strlen($organizationId)>64
            ||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$fileId)){
            throw new DomainException('Invalid tenant-owned media file reference.');
        }
        $stmt=$this->connection->prepare('SELECT file_id,storage_key,sha256,mime_type,size_bytes
            FROM cos_document_files WHERE organization_id=? AND file_id=? LIMIT 1');
        $stmt->execute([$organizationId,$fileId]);
        $file=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($file)
            ||!in_array($file['mime_type'],self::MIMES,true)
            ||(int)$file['size_bytes']<1||(int)$file['size_bytes']>self::MAX_BYTES
            ||!is_string($file['storage_key'])||strlen($file['storage_key'])>512
            ||!preg_match('/^documents\/[a-f0-9]{20}\//D',$file['storage_key'])
            ||!is_string($file['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$file['sha256'])) {
            throw new DomainException('COS Documents image is missing, invalid or not tenant-owned.');
        }
        $prefix='documents/'.substr(hash('sha256',$organizationId),0,20).'/';
        if(!str_starts_with($file['storage_key'],$prefix)) {
            throw new DomainException('COS Documents storage path crosses tenant boundary.');
        }
        if(!$this->storage->exists($file['storage_key'])) {
            throw new DomainException('Referenced Product image is missing from Platform Storage.');
        }
        $bytes=$this->storage->read($file['storage_key']);
        if(strlen($bytes)!==(int)$file['size_bytes']
            ||!hash_equals($file['sha256'],hash('sha256',$bytes))) {
            throw new DomainException('Product media bytes no longer match the verified Documents checksum.');
        }
        $detected=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if($detected!==$file['mime_type']||@getimagesizefromstring($bytes)===false) {
            throw new DomainException('Product media MIME or image content is invalid.');
        }
        return ['file_id'=>$fileId,'storage_key'=>$file['storage_key'],
            'sha256'=>$file['sha256'],'mime_type'=>$file['mime_type'],'bytes'=>$bytes];
    }
}
