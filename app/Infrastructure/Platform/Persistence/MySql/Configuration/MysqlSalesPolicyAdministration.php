<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Configuration;

use DomainException;
use Domains\Sales\Application\Contract\SalesPolicyAdministrationInterface;
use Domains\Sales\Automation\Policy\SalesPolicyDefinitionCatalog;
use Kernel\Action\ActionProposal;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Policy\Contract\PolicyRepositoryInterface;
use Kernel\Policy\Service\PolicyContextBuilder;
use Kernel\Policy\Service\PolicyEngine;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MysqlSalesPolicyAdministration implements SalesPolicyAdministrationInterface
{
    private const DOMAIN='sales';
    public function __construct(
        private PDO $connection,
        private SalesPolicyDefinitionCatalog $definitions,
        private DomainModuleRegistry $domains,
        private PolicyRepositoryInterface $policies,
        private PolicyContextBuilder $contexts,
        private PolicyEngine $engine,
    ) {}

    public function catalog(string $organizationId): array
    {
        return [...$this->definitions->catalog(),'actions'=>$this->actionCatalog(),'pipelines'=>$this->pipelines($organizationId)];
    }

    public function actions(string $organizationId): array
    {
        $actions=[];
        foreach ($this->actionCatalog() as $action) $actions[$action['type']]=[...$action,'policies'=>[]];
        $statement=$this->connection->prepare('SELECT * FROM cos_policies WHERE organization_id=:organization_id AND (domain_name=:domain_name OR action_type LIKE "sales.%") AND status<>"ARCHIVED" ORDER BY action_type,priority,id');
        $statement->execute(['organization_id'=>$organizationId,'domain_name'=>self::DOMAIN]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($actions[$row['action_type']])) continue;
            $actions[$row['action_type']]['policies'][]=$this->normalizeRow($row);
        }
        return array_values($actions);
    }

    public function create(string $organizationId,array $input,string $actorId): array
    {
        $actionType=$this->actionType($input);
        $normalized=$this->definitions->normalize($input);
        $id=bin2hex(random_bytes(16)); $now=$this->now();
        $code='sales.admin.'.substr(preg_replace('/[^a-z0-9]+/','-',strtolower($normalized['name'])) ?: 'policy',0,80).'.'.substr($id,0,8);
        $statement=$this->connection->prepare('INSERT INTO cos_policies (id,organization_id,domain_name,code,name,action_type,conditions,decision,decision_reason,priority,version,status,ownership,configuration_version,admin_modified_at,created_at,updated_at) VALUES (:id,:organization_id,:domain_name,:code,:name,:action_type,:conditions,:decision,:decision_reason,:priority,1,:status,"ADMIN",1,:now,:now,:now)');
        $this->connection->beginTransaction();
        try {
            $statement->execute(['id'=>$id,'organization_id'=>$organizationId,'domain_name'=>self::DOMAIN,'code'=>$code,'name'=>$normalized['name'],'action_type'=>$actionType,'conditions'=>json_encode($normalized['conditions'],JSON_THROW_ON_ERROR),'decision'=>$normalized['decision'],'decision_reason'=>$normalized['reason'],'priority'=>$normalized['priority'],'status'=>$normalized['enabled']?'ACTIVE':'DISABLED','now'=>$now]);
            $after=$this->row($organizationId,$id) ?? throw new RuntimeException('Policy creation failed.');
            $this->revision($organizationId,$id,1,'CREATE',$actorId,null,$after);
            $this->connection->commit(); return $this->normalizeRow($after);
        } catch(Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw $e;}
    }

    public function update(string $organizationId,string $policyId,array $input,int $expectedVersion,string $actorId): array
    {
        if($expectedVersion<1)throw new DomainException('configuration_version is required.');
        $before=$this->row($organizationId,$policyId) ?? throw new DomainException('Sales policy not found.');
        if(!$this->domains->ownsAction(self::DOMAIN,(string)$before['action_type']))throw new DomainException('Policy is not owned by Sales.');
        if((int)$before['configuration_version']!==$expectedVersion)throw new RuntimeException('CONFIGURATION_CONFLICT');
        if(isset($input['action_type']) && trim((string)$input['action_type'])!==(string)$before['action_type'])throw new DomainException('Policy action type is immutable.');
        $normalized=$this->definitions->normalize($input+['name'=>$before['name'],'decision'=>$before['decision'],'enabled'=>$before['status']==='ACTIVE','priority'=>$before['priority'],'conditions'=>json_decode((string)$before['conditions'],true)??[],'decision_reason'=>$before['decision_reason']]);
        $status=$normalized['enabled']?'ACTIVE':'DISABLED'; $next=$expectedVersion+1; $now=$this->now();
        $statement=$this->connection->prepare('UPDATE cos_policies SET domain_name=:domain_name,name=:name,conditions=:conditions,decision=:decision,decision_reason=:decision_reason,priority=:priority,status=:status,ownership="ADMIN",configuration_version=:next,admin_modified_at=:now,updated_at=:now WHERE organization_id=:organization_id AND id=:id AND configuration_version=:expected');
        $this->connection->beginTransaction();
        try{$statement->execute(['domain_name'=>self::DOMAIN,'name'=>$normalized['name'],'conditions'=>json_encode($normalized['conditions'],JSON_THROW_ON_ERROR),'decision'=>$normalized['decision'],'decision_reason'=>$normalized['reason'],'priority'=>$normalized['priority'],'status'=>$status,'next'=>$next,'now'=>$now,'organization_id'=>$organizationId,'id'=>$policyId,'expected'=>$expectedVersion]);if($statement->rowCount()!==1)throw new RuntimeException('CONFIGURATION_CONFLICT');$after=$this->row($organizationId,$policyId)??[];$action=$before['status']!==$status?($status==='ACTIVE'?'ENABLE':'DISABLE'):'UPDATE';$this->revision($organizationId,$policyId,$next,$action,$actorId,$before,$after);$this->connection->commit();return $this->normalizeRow($after);}catch(Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw $e;}
    }

    public function archive(string $organizationId,string $policyId,int $expectedVersion,string $actorId): array
    {
        $before=$this->row($organizationId,$policyId)??throw new DomainException('Sales policy not found.');
        if((int)$before['configuration_version']!==$expectedVersion)throw new RuntimeException('CONFIGURATION_CONFLICT');
        $next=$expectedVersion+1;$statement=$this->connection->prepare('UPDATE cos_policies SET status="ARCHIVED",ownership="ADMIN",configuration_version=:next,admin_modified_at=NOW(6),updated_at=NOW(6) WHERE organization_id=:organization_id AND id=:id AND configuration_version=:expected');
        $this->connection->beginTransaction();try{$statement->execute(['next'=>$next,'organization_id'=>$organizationId,'id'=>$policyId,'expected'=>$expectedVersion]);if($statement->rowCount()!==1)throw new RuntimeException('CONFIGURATION_CONFLICT');$after=$this->row($organizationId,$policyId)??[];$this->revision($organizationId,$policyId,$next,'ARCHIVE',$actorId,$before,$after);$this->connection->commit();return $this->normalizeRow($after);}catch(Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw $e;}
    }

    public function preview(string $organizationId,array $input): array
    {
        $actionType=$this->actionType($input);$dealId=trim((string)($input['deal_id']??''));if($dealId===''||!ctype_digit($dealId))throw new DomainException('deal_id is required.');
        $role=strtoupper(trim((string)($input['actor_role']??'AGENT')));if(!in_array($role,$this->definitions->catalog()['roles'],true))throw new DomainException('Unsupported actor role.');
        $risk=strtoupper(trim((string)($input['risk_level']??'LOW')));if(!in_array($risk,$this->definitions->catalog()['risks'],true))throw new DomainException('Unsupported risk level.');
        $confidence=$input['confidence']??null;if($confidence!==null&&$confidence!==''&&(!is_numeric($confidence)||(float)$confidence<0||(float)$confidence>1))throw new DomainException('Confidence must be between 0 and 1.');
        $parameters=[];if(trim((string)($input['target_stage_id']??''))!=='')$parameters['stage_id']=trim((string)$input['target_stage_id']);
        $proposal=new ActionProposal($actionType,'deal',$dealId,$parameters,'SYSTEM','policy-preview','MANUAL',$risk,'preview:'.bin2hex(random_bytes(8)),['actor'=>['role'=>$role],'confidence'=>$confidence===''?null:($confidence===null?null:(float)$confidence)]);
        $context=$this->contexts->build($organizationId,$proposal);$evaluation=$this->engine->evaluate($actionType,$context,$this->policies->activeFor($organizationId,$actionType));
        return ['decision'=>$evaluation->decision->value,'matched_policy'=>$evaluation->policy?->id,'matched_policy_name'=>$evaluation->policy?->name,'reason'=>$evaluation->reason,'context'=>$context,'executed'=>false];
    }

    public function revisions(string $organizationId,string $policyId,int $limit=100): array
    {
        $limit=max(1,min(200,$limit));$statement=$this->connection->prepare('SELECT entity_version,action,actor_type,actor_id,reason,before_payload,after_payload,created_at FROM cos_configuration_revisions WHERE organization_id=:organization_id AND domain_name=:domain_name AND configuration_type="POLICY" AND entity_id=:entity_id ORDER BY id DESC LIMIT '.$limit);$statement->execute(['organization_id'=>$organizationId,'domain_name'=>self::DOMAIN,'entity_id'=>$policyId]);$rows=$statement->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){foreach(['before_payload','after_payload'] as $f)if(is_string($row[$f]??null))$row[$f]=json_decode((string)$row[$f],true)??[];$row['entity_version']=(int)$row['entity_version'];}return $rows;
    }

    private function actionCatalog(): array
    {
        $labels=['sales.send_message'=>'Send Message','sales.change_stage'=>'Change Stage','sales.create_task'=>'Create Task','sales.create_followup'=>'Create Follow-up','sales.schedule_followup'=>'Schedule Follow-up','sales.request_document'=>'Request Document','sales.schedule_meeting'=>'Schedule Meeting','sales.request_manager_review'=>'Request Manager Review','sales.assign_owner'=>'Assign Owner'];
        $out=[];foreach($this->domains->actionTypesForDomain(self::DOMAIN) as $type)$out[]=['type'=>$type,'label'=>$labels[$type]??$type];return $out;
    }
    private function actionType(array $input): string{$type=trim((string)($input['action_type']??$input['action']??''));if($type===''||!$this->domains->ownsAction(self::DOMAIN,$type))throw new DomainException('Unsupported Sales action type.');return $type;}
    private function pipelines(string $organizationId): array{$s=$this->connection->prepare('SELECT id,code,name FROM sales_pipelines WHERE organization_id=:organization_id AND status="ACTIVE" ORDER BY name');$s->execute(['organization_id'=>$organizationId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$stages=$this->connection->prepare('SELECT id,pipeline_id,code,name,is_won,is_lost FROM sales_pipeline_stages WHERE organization_id=:organization_id AND status="ACTIVE" ORDER BY pipeline_id,sort_order');$stages->execute(['organization_id'=>$organizationId]);$by=[];foreach($stages->fetchAll(PDO::FETCH_ASSOC) as $row)$by[$row['pipeline_id']][]=$row;foreach($rows as &$row)$row['stages']=$by[$row['id']]??[];return $rows;}
    private function row(string $organizationId,string $id): ?array{$s=$this->connection->prepare('SELECT * FROM cos_policies WHERE organization_id=:organization_id AND id=:id LIMIT 1');$s->execute(['organization_id'=>$organizationId,'id'=>$id]);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}
    private function normalizeRow(array $row): array{$row['conditions']=is_string($row['conditions']??null)?(json_decode((string)$row['conditions'],true)??[]):($row['conditions']??[]);$row['configuration_version']=(int)($row['configuration_version']??1);$row['priority']=(int)($row['priority']??100);$row['enabled']=($row['status']??'')==='ACTIVE';return $row;}
    private function revision(string $org,string $id,int $version,string $action,string $actorId,?array $before,array $after):void{$s=$this->connection->prepare('INSERT INTO cos_configuration_revisions (organization_id,domain_name,configuration_type,entity_id,entity_version,action,actor_type,actor_id,before_payload,after_payload,created_at) VALUES (:organization_id,:domain_name,"POLICY",:entity_id,:version,:action,"USER",:actor_id,:before_payload,:after_payload,NOW(6))');$s->execute(['organization_id'=>$org,'domain_name'=>self::DOMAIN,'entity_id'=>$id,'version'=>$version,'action'=>$action,'actor_id'=>$actorId,'before_payload'=>$before===null?null:json_encode($this->normalizeRow($before),JSON_THROW_ON_ERROR),'after_payload'=>json_encode($this->normalizeRow($after),JSON_THROW_ON_ERROR)]);}
    private function now():string{return (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');}
}
