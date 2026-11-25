<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingCommerceOrderReadPort;
use Domains\Marketing\Application\Contract\MarketingCommerceLifecycleReadPort;
use Domains\Sales\Application\Contract\SalesCommerceOrderLifecycleEvidencePort;
use Domains\Sales\Application\Contract\SalesCommerceOrderEvidencePort;
use Domains\Sales\Application\Contract\SalesCommerceOrderJourneyReadPort;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
final readonly class CommerceOrderReadAdapter implements MarketingCommerceOrderReadPort, MarketingCommerceLifecycleReadPort
{
    // The two independently developed releases may be installed in either order.
    // Missing source is unavailable, never an empty or zero-sales report.
    public function __construct(private ActiveModuleResolver $modules,
        private ?SalesCommerceOrderEvidencePort $placements=null,
        private ?SalesCommerceOrderJourneyReadPort $journeys=null,
        private ?SalesCommerceOrderLifecycleEvidencePort $lifecycleEvidence=null){}
    public function assertAvailable(TenantContext $actor):void
    {
        if(!$this->modules->isEnabled($actor->organizationId()->value(),'sales')||$this->placements===null||$this->journeys===null)
            throw new DomainException('Native Sales commerce attribution source unavailable.');
    }
    public function lifecycle(TenantContext $actor,string $orderId,int $afterRevision,int $limit):?array
    {
        $this->assertAvailable($actor);
        if(!preg_match('/^[a-f0-9]{32}$/D',$orderId))throw new DomainException('Invalid native Order ID.');
        return $this->lifecycleEvidence?->lifecycleEvidence($actor->organizationId()->value(),$orderId,$afterRevision,$limit);
    }
    public function get(TenantContext $actor,string $orderId):?array
    {
        $this->assertAvailable($actor);$org=$actor->organizationId()->value();
        if(!preg_match('/^[a-f0-9]{32}$/D',$orderId))throw new DomainException('Invalid native Order ID.');
        $p=$this->placements->placement($org,$orderId);
        if($p===null)return null;
        if(($p['organization_id']??null)!==$org||($p['order_id']??null)!==$orderId)throw new DomainException('Order source scope mismatch.');
        $j=$p['channel']==='shopify'?$this->journeys->journey($org,$orderId):null;
        // Project only commercial facts and exact source tags. No customer data, external IDs or raw click history.
        $p=array_intersect_key($p,array_flip(['schema_version','event_id','event_type','organization_id','order_id',
            'revision','occurred_at','channel','currency','order_total','financial_evidence_reference','realized_revenue','attribution_status','provenance','attribution_observations']));
        $tags=(array)($p['attribution_observations']??[]);
        $p['attribution_observations']=array_intersect_key($tags,array_flip(['utm_source','utm_medium','utm_campaign','campaign_id']));
        return ['placement'=>$p,'journey'=>$j];
    }
}
