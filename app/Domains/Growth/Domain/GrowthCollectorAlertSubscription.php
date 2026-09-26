<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class GrowthCollectorAlertSubscription
{
    private bool $enabled;

    public function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly string $recipientEmail,
        public readonly ?string $recipientName,
        public readonly string $locale,
        bool $enabled=true,
    ) {
        if(trim($id)==='')throw new InvalidArgumentException('Growth collector alert subscription id is required.');
        if(filter_var($recipientEmail,FILTER_VALIDATE_EMAIL)===false){
            throw new InvalidArgumentException('Growth collector alert subscription requires a valid email address.');
        }
        if(mb_strlen($recipientEmail)>254)throw new InvalidArgumentException('Growth collector alert email is too long.');
        if($recipientName!==null&&mb_strlen(trim($recipientName))>191){
            throw new InvalidArgumentException('Growth collector alert recipient name is too long.');
        }
        if(!preg_match('/^[A-Za-z]{2}(?:[-_][A-Za-z]{2})?$/',trim($locale))){
            throw new InvalidArgumentException('Growth collector alert locale is invalid.');
        }
        $this->enabled=$enabled;
    }

    public function enabled():bool{return $this->enabled;}
    public function enable():void{$this->enabled=true;}
    public function disable():void{$this->enabled=false;}
}
