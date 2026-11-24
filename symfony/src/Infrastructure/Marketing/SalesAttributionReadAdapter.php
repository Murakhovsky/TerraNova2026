<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingSalesAttributionReadPort;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
final readonly class SalesAttributionReadAdapter implements MarketingSalesAttributionReadPort
{
    public function __construct(private SalesWorkspaceReadModelInterface $sales,private ActiveModuleResolver $modules){}
    public function lead(TenantContext $actor,string $leadId):?array
    {
        $org=$actor->organizationId()->value();
        if(!$this->modules->isEnabled($org,'sales')||!preg_match('/^[1-9][0-9]{0,17}$/D',$leadId))throw new DomainException('Sales source unavailable.');
        $l=$this->sales->lead($org,(int)$leadId);if($l===null)return null;
        if((string)($l['id']??'')!==$leadId||(string)($l['organization_id']??'')!==$org)throw new DomainException('Sales lead scope mismatch.');
        $deal=$l['opportunity']??null;$outcome=null;
        if(is_array($deal)&&(string)($deal['organization_id']??'')===$org&&(string)($deal['id']??'')===(string)($l['client_case_id']??'')
            &&(string)($deal['lead_id']??'')===$leadId){
            $won=false;
            foreach($this->sales->pipelines($org) as $pipeline){
                if((string)$pipeline['id']!==(string)($deal['pipeline_id']??''))continue;
                foreach($pipeline['stages']??[] as $stage)
                    if((string)$stage['id']===(string)($deal['stage_id']??'')&&(int)($stage['is_won']??0)===1)$won=true;
            }
            $outcome=['deal_id'=>(string)$deal['id'],'won'=>$won&&($deal['status']??null)==='closed',
                'closed_at'=>$deal['closed_at']??null,'currency'=>$deal['currency']??null,
                'deal_value'=>isset($deal['deal_value'])?(string)$deal['deal_value']:null];
        }
        return ['lead_id'=>$leadId,'created_at'=>(string)$l['created_at'],'status'=>(string)$l['status'],
            'utm_source'=>$l['utm_source']??null,'utm_medium'=>$l['utm_medium']??null,'utm_campaign'=>$l['utm_campaign']??null,'deal'=>$outcome];
    }
}
