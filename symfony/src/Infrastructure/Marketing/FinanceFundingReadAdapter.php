<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;

use DomainException;
use Domains\Finance\Application\Contract\FinanceBudgetControlInterface;
use Domains\Marketing\Application\Contract\MarketingFinanceFundingReadPort;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;

/** No Finance SQL or mutation. Read envelope revision on both sides of reservation lookup. */
final readonly class FinanceFundingReadAdapter implements MarketingFinanceFundingReadPort
{
    public function __construct(private FinanceBudgetControlInterface $finance,private ActiveModuleResolver $modules){}
    public function observe(TenantContext $actor,string $envelopeId,string $reservationId):?array
    {
        if(!$this->modules->isEnabled($actor->organizationId()->value(),'finance')){
            throw new DomainException('Finance module inactive.');
        }
        $before=$this->finance->envelope($actor,$envelopeId);
        if($before===null)return null;
        $match=null;
        foreach($this->finance->reservations($actor,$envelopeId) as $reservation){
            if(($reservation['id']??null)===$reservationId){$match=$reservation;break;}
        }
        $after=$this->finance->envelope($actor,$envelopeId);
        if($after===null||$before!==$after)throw new DomainException('Finance observation changed; read again.');
        // F1 list is bounded to 500; a missing row is never inferred to be funded.
        return $match===null?null:['envelope'=>$after,'reservation'=>$match];
    }
}
