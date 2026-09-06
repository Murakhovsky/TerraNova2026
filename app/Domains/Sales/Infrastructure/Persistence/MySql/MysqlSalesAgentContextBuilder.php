<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use PDO;
use RuntimeException;

final readonly class MysqlSalesAgentContextBuilder implements AgentContextBuilderInterface
{
    public function __construct(private PDO $connection) {}

    public function build(AgentInvocation $invocation): array
    {
        if (!in_array($invocation->subjectType, ['deal', 'client_case'], true)) throw new RuntimeException('Unsupported Sales agent subject.');
        $deal = $this->one('SELECT c.id,c.public_id,c.title,c.status,COALESCE(s.code,UPPER(c.stage)) stage,c.priority,c.source,'
            . 'c.budget_min,c.budget_max,COALESCE(c.deal_value,c.budget_max) value,c.currency,COALESCE(c.probability,s.probability_default) probability,'
            . 'c.description,c.next_contact_at,c.last_activity_at,c.expected_close_at,c.updated_at,c.person_id,c.inbound_request_id lead_id,'
            . 'p.full_name,p.notes customer_notes,u.full_name assigned_manager,pl.name pipeline_name FROM tn_client_cases c '
            . 'INNER JOIN tn_people p ON p.id=c.person_id AND p.organization_id=c.organization_id '
            . 'LEFT JOIN tn_users u ON u.id=c.assigned_user_id AND u.organization_id=c.organization_id '
            . 'LEFT JOIN sales_pipelines pl ON pl.id=c.pipeline_id AND pl.organization_id=c.organization_id '
            . 'LEFT JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.organization_id=c.organization_id '
            . 'WHERE c.id=:id AND c.organization_id=:organization_id LIMIT 1', ['id'=>$invocation->subjectId,'organization_id'=>$invocation->organizationId]);
        if ($deal === null) throw new RuntimeException('Sales agent subject was not found.');
        $scope=['id'=>$invocation->subjectId,'organization_id'=>$invocation->organizationId];
        $activities=$this->all('SELECT activity_type,title,LEFT(body,2000) body,due_at,completed_at,created_at FROM tn_client_case_activities '
            . 'WHERE client_case_id=:id AND organization_id=:organization_id AND created_at>=NOW()-INTERVAL 180 DAY ORDER BY created_at DESC LIMIT 30',$scope);
        $communications=$this->all('SELECT channel,direction,LEFT(body,2000) body,occurred_at FROM sales_communications '
            . 'WHERE deal_id=:id AND organization_id=:organization_id AND occurred_at>=NOW()-INTERVAL 90 DAY ORDER BY occurred_at DESC LIMIT 20',$scope);
        $lead=!empty($deal['lead_id'])?$this->one('SELECT id,source_page source,status,request_intent,created_at,last_contacted_at last_contact_at,next_contact_at FROM tn_leads '
            . 'WHERE id=:id AND organization_id=:organization_id LIMIT 1',['id'=>$deal['lead_id'],'organization_id'=>$invocation->organizationId]):null;
        $org=['organization_id'=>$invocation->organizationId];
        $rules=$this->all('SELECT code,name,trigger_type,conditions,effect FROM cos_rules WHERE organization_id=:organization_id AND status="ACTIVE" AND trigger_type LIKE "sales.%" ORDER BY priority LIMIT 20',$org);
        $policies=$this->all('SELECT code,action_type,conditions,decision FROM cos_policies WHERE organization_id=:organization_id AND status="ACTIVE" AND action_type LIKE "sales.%" ORDER BY priority LIMIT 20',$org);
        $property=$this->one('SELECT p.public_id,p.title,p.price_amount,p.price_currency,m.match_status,m.score FROM tn_client_case_property_matches m INNER JOIN tn_properties p ON p.id=m.property_id WHERE m.client_case_id=:id AND m.organization_id=:organization_id ORDER BY m.score DESC,m.updated_at DESC LIMIT 1',$scope);
        $context = [
            'deal'=>$deal,'person'=>['id'=>$deal['person_id'],'name'=>$deal['full_name'],'notes'=>$deal['customer_notes']],
            'lead'=>$lead,'pipeline'=>['name'=>$deal['pipeline_name'],'stage'=>$deal['stage']],
            'activities'=>$activities,'communications'=>$communications,'last_contact'=>$communications[0]??$activities[0]??null,
            'next_action'=>['at'=>$deal['next_contact_at']],'sales_history'=>array_slice($activities,0,10),
            'assigned_manager'=>['name'=>$deal['assigned_manager']],'product_or_property'=>$property,
            'metrics'=>['activity_count_180d'=>count($activities),'communication_count_90d'=>count($communications),
                'days_since_activity'=>$deal['last_activity_at']?max(0,(int)floor((time()-strtotime((string)$deal['last_activity_at']))/86400)):null],
            'rules'=>$rules,'policies'=>$policies,'goals'=>[['type'=>'advance_deal_safely','target'=>'next_valid_pipeline_stage']],
            'question'=>$invocation->question,'context_references'=>$invocation->contextReferences,
            '_limits'=>['activity_limit'=>30,'communication_limit'=>20,'history_days'=>180,'pii'=>'redacted_by_kernel','token_budget'=>12000],
        ];
        return $this->enforceBudget($context,12000);
    }
    private function enforceBudget(array $context,int $tokens):array
    {
        $max=max(1024,$tokens*4);$target=$max-256;
        while(strlen(json_encode($context,JSON_THROW_ON_ERROR))>$target){
            if(count($context['communications'])>3){array_pop($context['communications']);continue;}
            if(count($context['activities'])>5){array_pop($context['activities']);continue;}
            if(count($context['sales_history'])>3){array_pop($context['sales_history']);continue;}
            if(count($context['rules'])>3){array_pop($context['rules']);continue;}
            if(count($context['policies'])>3){array_pop($context['policies']);continue;}
            if(!$this->shrinkLargestString($context))break;
        }
        if(strlen(json_encode($context,JSON_THROW_ON_ERROR))>$target){
            $context=['deal'=>array_intersect_key($context['deal'],array_flip(['id','title','status','stage','priority','value','currency','probability','next_contact_at','last_activity_at'])),'person'=>['id'=>$context['person']['id']??null],'lead'=>$context['lead']===null?null:array_intersect_key($context['lead'],array_flip(['id','status','source'])),'pipeline'=>$context['pipeline'],'activities'=>[],'communications'=>[],'last_contact'=>$context['last_contact'],'next_action'=>$context['next_action'],'sales_history'=>[],'assigned_manager'=>$context['assigned_manager'],'product_or_property'=>null,'metrics'=>$context['metrics'],'rules'=>[],'policies'=>[],'goals'=>[],'question'=>mb_substr((string)$context['question'],0,500),'context_references'=>[],'_limits'=>$context['_limits']];
        }
        $context['_limits']['estimated_tokens']=(int)ceil(strlen(json_encode($context,JSON_THROW_ON_ERROR))/4);
        if(strlen(json_encode($context,JSON_THROW_ON_ERROR))>$max)throw new RuntimeException('Sales context cannot fit the configured token budget.');
        return $context;
    }
    private function shrinkLargestString(array &$value):bool
    {
        $largestPath=null;$largestLength=0;$walk=function(array &$node,array $path=[])use(&$walk,&$largestPath,&$largestLength):void{foreach($node as $key=>&$item){$current=[...$path,$key];if(is_array($item)){$walk($item,$current);}elseif(is_string($item)&&mb_strlen($item)>$largestLength){$largestLength=mb_strlen($item);$largestPath=$current;}}};$walk($value);if($largestPath===null||$largestLength<=64)return false;$cursor=&$value;foreach($largestPath as $key)$cursor=&$cursor[$key];$cursor=mb_substr($cursor,0,max(64,(int)floor($largestLength/2)));return true;
    }
    private function all(string $sql,array $params): array {$s=$this->connection->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $params): ?array {$s=$this->connection->prepare($sql);$s->execute($params);$r=$s->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r;}
}
