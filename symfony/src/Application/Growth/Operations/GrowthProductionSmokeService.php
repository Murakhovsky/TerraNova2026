<?php
declare(strict_types=1);

namespace App\Application\Growth\Operations;

use Domains\Growth\Application\Contract\GrowthWorkspaceReadModelInterface;
use InvalidArgumentException;
use Kernel\Module\ModuleReadinessDiagnostic;
use Throwable;

final readonly class GrowthProductionSmokeService
{
    public function __construct(
        private ModuleReadinessDiagnostic $readiness,
        private GrowthWorkspaceReadModelInterface $workspace,
    ) {}

    public function run(string $organizationId,?string $candidateId=null,bool $live=false):array
    {
        $organizationId=trim($organizationId);
        if($organizationId===''||mb_strlen($organizationId)>64){
            throw new InvalidArgumentException('Growth production smoke organization is invalid.');
        }
        $candidateId=$candidateId===null?null:trim($candidateId);
        if($live&&($candidateId===null||$candidateId==='')){
            throw new InvalidArgumentException('Live Growth production smoke requires --candidate.');
        }

        $readiness=$this->readiness->diagnose($organizationId);
        $module=$this->growthModule($readiness);
        $checks=[];
        $this->check($checks,'schema_status',($readiness['schema_status']??null)==='AVAILABLE',$readiness['schema_status']??null,'AVAILABLE');
        $this->check($checks,'growth_module_ready',($module['status']??null)==='READY',$module['status']??null,'READY');
        $this->check($checks,'growth_module_active',($module['active']??false)===true,$module['active']??false,'true');
        $this->check($checks,'growth_schema_current',($module['current']??false)===true,$module['current']??false,'true');
        $this->check($checks,'growth_migrations_complete',($module['missing_migrations']??[])===[],$module['missing_migrations']??null,'[]');

        $workspace=null;
        try{
            $workspace=$this->workspace->overview($organizationId);
            $this->check($checks,'growth_workspace_read',true,'ok','read succeeds');
        }catch(Throwable $error){
            $this->check($checks,'growth_workspace_read',false,get_class($error),'read succeeds');
        }

        $evidence=null;
        if($live&&$candidateId!==null){
            try{
                $evidence=$this->workspace->productionEvidence($organizationId,$candidateId);
                $this->check($checks,'candidate_exists',($evidence['exists']??false)===true,$evidence['exists']??false,'true');
                if(($evidence['exists']??false)===true){
                    $this->check($checks,'sales_golden_path',($evidence['target_domain']??null)==='sales',$evidence['target_domain']??null,'sales');
                    $this->check($checks,'market_membership',(int)($evidence['market_membership_count']??0)>0,$evidence['market_membership_count']??0,'> 0');
                    $this->check($checks,'signal_evidence',(int)($evidence['signal_count']??0)>0,$evidence['signal_count']??0,'> 0');
                    if(($evidence['subject_type']??null)==='account'){
                        $this->check($checks,'account_materialized',($evidence['account_exists']??false)===true,$evidence['account_exists']??false,'true');
                        $this->check($checks,'buying_committee_assessed',(int)($evidence['committee_assessment_count']??0)>0,$evidence['committee_assessment_count']??0,'> 0');
                    }
                    $this->check($checks,'accepted_outreach_recommendation',(int)($evidence['accepted_recommendation_count']??0)>0,$evidence['accepted_recommendation_count']??0,'> 0');
                    $this->check($checks,'governed_execution',(int)($evidence['execution_count']??0)>0,$evidence['execution_count']??0,'> 0');
                    $this->check($checks,'inbound_reply',(int)($evidence['response_count']??0)>0,$evidence['response_count']??0,'> 0');
                    $this->check($checks,'reply_learning_outcome',(int)($evidence['reply_outcome_count']??0)>0,$evidence['reply_outcome_count']??0,'> 0');
                    $this->check(
                        $checks,'sales_handoff_or_route',
                        (int)($evidence['sales_route_count']??0)>0||(int)($evidence['accepted_sales_handoff_count']??0)>0,
                        ['routes'=>$evidence['sales_route_count']??0,'handoffs'=>$evidence['accepted_sales_handoff_count']??0],
                        'completed Sales route or accepted Sales handoff',
                    );
                    $this->check($checks,'sales_outcome_feedback',(int)($evidence['sales_outcome_count']??0)>0,$evidence['sales_outcome_count']??0,'> 0');
                    $this->check($checks,'terminal_business_outcome',(int)($evidence['terminal_outcome_count']??0)>0,$evidence['terminal_outcome_count']??0,'> 0');
                }
            }catch(Throwable $error){
                $this->check($checks,'live_candidate_evidence',false,get_class($error),'read succeeds');
            }
        }

        return [
            'ok'=>!in_array(false,array_column($checks,'ok'),true),
            'mode'=>$live?'live':'basic',
            'organization_id'=>$organizationId,
            'candidate_id'=>$candidateId,
            'checks'=>$checks,
            'readiness'=>$module,
            'workspace'=>$workspace,
            'evidence'=>$evidence,
        ];
    }

    private function growthModule(array $snapshot):array
    {
        foreach($snapshot['modules']??[] as $module){
            if(is_array($module)&&($module['id']??null)==='growth')return $module;
        }
        return ['id'=>'growth','status'=>'MISSING','active'=>false,'current'=>false,'missing_migrations'=>['growth-module-not-discovered']];
    }

    private function check(array &$checks,string $name,bool $ok,mixed $observed,string $expected):void
    {
        $checks[]=['name'=>$name,'ok'=>$ok,'observed'=>$observed,'expected'=>$expected];
    }
}
