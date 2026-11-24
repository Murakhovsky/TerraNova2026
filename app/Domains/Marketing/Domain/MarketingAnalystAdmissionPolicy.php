<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;

use DomainException;

/** Explicit operator-provisioned tenant policy, never a client-supplied approval. */
final readonly class MarketingAnalystAdmissionPolicy
{
    public function __construct(public string $organizationId,public string $profileRef,public int $dailyRunLimit,
        public string $costControlRef='',public bool $enabled=false)
    {
        if(trim($organizationId)===''||strlen($organizationId)>190
            ||!preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$profileRef)||$dailyRunLimit<1||$dailyRunLimit>100
            ||($costControlRef!==''&&!preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$costControlRef))){
            throw new DomainException('Invalid analyst admission configuration.');
        }
    }

    public function assertAllowed(string $organizationId):void
    {
        if(!$this->enabled||$this->organizationId!==$organizationId||$this->costControlRef===''){
            throw new DomainException('Analyst admission disabled or external runtime cost-control acceptance missing.');
        }
    }
}
