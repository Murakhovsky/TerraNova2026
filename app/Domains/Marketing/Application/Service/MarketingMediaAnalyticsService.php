<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingMediaAnalyticsStore;
use Domains\Marketing\Application\Contract\MarketingPaidReadRepository;
use Domains\Marketing\Application\Contract\PaidMediaProviderInterface;
use Domains\Marketing\Domain\MarketingMediaAnalyticsPolicy as Policy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

final readonly class MarketingMediaAnalyticsService
{
    public function __construct(private MarketingPaidReadRepository $accounts,private PaidMediaProviderInterface $provider,
        private MarketingMediaAnalyticsStore $store,private ActiveModuleResolver $modules){}
    private function account(TenantContext $actor,string $id):array
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing')||!preg_match('/^mads-[a-f0-9]{24}$/D',$id))
            throw new DomainException('Active Marketing tenant authority required.');
        $a=$this->accounts->account($org,$id);
        if($a===null||$a['connection_status']!=='READ_ONLY'||$a['provider']!=='meta')throw new DomainException('Read-only media account unavailable.');
        return $a;
    }
    public function sync(TenantContext $actor,string $account,string $grain,string $from,string $to,string $key):array
    {
        Policy::scope($grain,$from,$to);$a=$this->account($actor,$account);$org=$actor->organizationId()->value();
        if(!preg_match('/^[A-Za-z0-9_.:-]{8,191}$/D',$key))throw new DomainException('Bounded media import key required.');
        $h=hash('sha256',json_encode([$actor->userId()->value(),$account,$a['revision'],$grain,$from,$to],JSON_THROW_ON_ERROR));
        $existing=$this->store->byKey($org,$key,$h);
        if($existing!==null)return $this->view($existing);
        // External call is read-only and completes before the local transaction.
        $rows=$this->provider->insights($org,$a['ad_account_id'],$a['credential_ref'],$from,$to,$grain);
        $r=$this->store->commit($org,$actor->userId()->value(),$key,$h,$a,$grain,$from,$to,$rows);
        return $this->view($r);
    }
    public function report(TenantContext $actor,string $account,string $grain,string $from,string $to,?string $baselineFrom,?string $baselineTo):array
    {
        Policy::scope($grain,$from,$to);$a=$this->account($actor,$account);$org=$actor->organizationId()->value();
        $r=$this->store->latest($org,$account,$grain,$from,$to);
        if($r===null)throw new DomainException('No complete media batch for exact scope.');
        $v=$this->view($r);$v['account_revision_current']=$a['revision']===$r['account_revision'];
        if(($baselineFrom===null)!==($baselineTo===null))throw new DomainException('Both comparison dates required.');
        if($baselineFrom!==null){
            Policy::scope($grain,$baselineFrom,$baselineTo);
            $b=$this->store->latest($org,$account,$grain,$baselineFrom,$baselineTo)??throw new DomainException('No baseline batch for exact scope.');
            $v['comparison']=Policy::comparison($r,$b);
        }
        return $v;
    }
    private function view(array $r):array
    {
        return [...$r,'totals'=>Policy::summarize($r['rows']),'comparison'=>null,'account_revision_current'=>true,
            'provider_default_attribution_not_causal'=>true,'payment_confirmed'=>false];
    }
}
