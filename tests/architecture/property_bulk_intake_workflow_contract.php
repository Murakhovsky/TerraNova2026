<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$read=static function(string $file) use($root):string {
    $body=file_get_contents($root.'/'.$file);
    if (!is_string($body)) throw new RuntimeException('Missing '.$file);
    return $body;
};
$check=static function(bool $good,string $error):void {
    if (!$good) throw new RuntimeException($error);
};
$def=require $root.'/app/Domains/Property/module.php';
$contracts=$def['contributions']['capability_contracts'];
$bulk=array_values(array_filter($contracts,static fn(array $x):bool=>($x['id']??'')==='property.bulk.create'));
$check(count($bulk)===1,'Missing exactly one bulk Action contract');
$contract=$bulk[0];
$check($contract['approval_policy']==='required' && $contract['idempotency']==='required'
    && $contract['execution_binding']==='action:property.bulk.create'
    && $contract['side_effect_level']==='external' && ($contract['retry_policy']['max_attempts']??0)===1,
    'Bulk Action requires strict canonical approval, fencing and no automatic replay.');
$handler=$read('app/Domains/Property/Automation/Action/PropertyBulkCreateHandler.php');
$check(str_contains($handler,'IdempotentExternalActionHandlerInterface')
    &&str_contains($handler,"ActionStatus::Running")
    &&str_contains($handler,"'USER'")
    &&str_contains($handler,"'APPROVAL_REQUIRED'")
    &&str_contains($handler,"/^wf:[a-f0-9]{64}$/D")
    &&str_contains($handler,"ActionStatus::Running"),'Bulk handler bypasses approved canonical Workflow Action.');
$service=$read('symfony/src/Persistence/Property/PropertyBulkIntakeService.php');
$check(str_contains($read('symfony/src/Persistence/Property/PropertyBulkPlanProposalService.php'),'$this->goals->proposePlan(')
    &&str_contains($service,'$this->writes->create(')
    &&str_contains($service,'$this->properties->getPropertyPresentation(')
    &&!str_contains($service,'FederationGoalStore')
    &&!str_contains($service,'GoalSpecification')
    &&!str_contains($service,'cos_workflow_runs')
    &&!str_contains($service,'cos_workflow_steps'),
    'Bulk service must reuse canonical Goal/Property and never own technical Workflow.');
$provider=$read('symfony/src/Persistence/Property/PropertyBulkRunOutcomeProvider.php');
$check(str_contains($provider,'attestForAudit(')
    &&str_contains($provider,'getPropertyReference(')
    &&str_contains($provider,'run_linked_action'),
    'Outcome provider must verify canonical receipt and independent Domain records.');
$module=$read('app/Domains/Property/Bootstrap/PropertyDomainModule.php');
$check(str_contains($module,'ActionOwningModuleInterface')
    &&str_contains($module,'PolicyDecision::ApprovalRequired')
    &&str_contains($module,'PropertyBulkCreateHandler::TYPE'),
    'Property Action owner and independent approval policy missing.');
$api=$read('symfony/src/Http/Api/V1/Controller/PropertyBulkIntakeController.php');
$ui=$read('symfony/src/Web/Property/PropertyBulkWorkspaceController.php');
$check(str_contains($api,'$this->csrf->isValid($request)')
    &&str_contains($ui,'$this->csrf->isValid($request)')
    &&str_contains($read('symfony/config/routes.yaml'),'/workspace/property/bulk'),
    'Property bulk endpoints must require human review and CSRF.');
foreach(['bulk-create-input.schema.json','bulk-create-output.schema.json'] as $schema) {
    $json=json_decode($read('resources/contracts/property/'.$schema),true,512,JSON_THROW_ON_ERROR);
    $check(($json['type']??'')==='object','Malformed Property Action schema.');
}
$output=json_decode($read('resources/contracts/property/bulk-create-output.schema.json'),true,512,JSON_THROW_ON_ERROR);
$check(($output['properties']['asset_ids']['minItems']??0)===1
    &&($output['properties']['asset_ids']['maxItems']??0)===10,
    'Output cardinality must match accepted 1-10 Property assets.');
echo "Property bulk approved Workflow contract PASS.\n";
