<?php
declare(strict_types=1);
namespace App\Application\ProductManagement;

use App\Application\Documents\Command\UploadDocumentCommand;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use DomainException;

/** Narrow Documents upload boundary; has no Product mutation authority. */
final readonly class ProductMediaDocumentUpload
{
    public function __construct(private CommandBusInterface $commands){}
    public function upload(TenantContext $actor,string $key,string $trace,string $name,string $mime,string $bytes):array
    {
        $user=$actor->userId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!ctype_digit($user)||(int)$user<1||!preg_match('/^[a-f0-9]{32}$/D',$key)||strlen($bytes)<1||strlen($bytes)>10485760)
            throw new DomainException('Trusted Documents image upload context required.');
        if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)
            ||(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!==$mime||@getimagesizefromstring($bytes)===false)
            throw new DomainException('Invalid image content.');
        return $this->commands->dispatch(new UploadDocumentCommand($actor->organizationId(),(int)$user,$trace,
            'pm-media-upload-'.$key,['title'=>$name,'filename'=>$name,'mime_type'=>$mime,'content_base64'=>base64_encode($bytes)]));
    }
}
