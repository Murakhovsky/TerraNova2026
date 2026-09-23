<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use DomainException;
use Domains\Growth\Application\AI\GrowthOptimizationPrompt;
use Domains\Growth\Domain\IcpCriteria;
use Domains\Growth\Domain\IcpProfile;
use Domains\Growth\Domain\LearningOptimizationRecommendation;
use Domains\Growth\Domain\OptimizationRecommendationStatus;
use Domains\Growth\Domain\OptimizationTargetType;
use Domains\Growth\Domain\QualificationPolicy;
use Domains\Growth\Domain\QualificationPolicyCriteria;
use Domains\Growth\Infrastructure\AI\StructuredLlmGrowthOptimizationGateway;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;
use Kernel\Llm\StructuredLlmResponse;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV0170(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$org=OrganizationId::fromString('org-1');

$icp=IcpProfile::draft('icp-1',$org,'ICP',new IcpCriteria(industries:['saas']));
try{
    $icp->revise('Invalid',new IcpCriteria(industries:['fintech']));
    throw new RuntimeException('Draft ICP must not be revised.');
}catch(DomainException){}
$icp->activate();
$icpDraft=$icp->revise('ICP V2',new IcpCriteria(industries:['saas'],requiredSignalTypes:['leadership_change']));
expectGrowthV0170($icpDraft->revision===2,'Active ICP revision must create next draft revision.');

$policy=QualificationPolicy::draft(
    'policy-1',$org,'Policy',
    new QualificationPolicyCriteria(
        ['fit'=>60,'need'=>60,'timing'=>50,'access'=>40,'value'=>50],
        ['fit'=>20,'need'=>20],
        0.6,
    ),
);
$policy->activate();
$policyDraft=$policy->revise(
    'Policy V2',
    new QualificationPolicyCriteria(
        ['fit'=>65,'need'=>60,'timing'=>55,'access'=>40,'value'=>50],
        ['fit'=>25,'need'=>20],
        0.65,
    ),
);
expectGrowthV0170($policyDraft->revision===2,'Active qualification policy revision must create next draft revision.');

$recommendation=new LearningOptimizationRecommendation(
    'GORC-1',$org,OptimizationTargetType::IcpProfile,'icp-1',1,'ICP learned',
    [
        'industries'=>['saas'],'regions'=>[],'min_employees'=>null,'max_employees'=>null,
        'technologies'=>[],'required_signal_types'=>['leadership_change'],
    ],
    'Won Candidates correlate with leadership-change evidence.',['learning:signal:abc'],['Small sample'],['Correlation is not causation'],
    0.72,'fixture-provider','fixture-model',GrowthOptimizationPrompt::PROMPT_VERSION,GrowthOptimizationPrompt::SCHEMA_VERSION,
    new DateTimeImmutable('2026-09-23T06:00:00+00:00'),
);
expectGrowthV0170($recommendation->status()===OptimizationRecommendationStatus::Proposed,'Optimization recommendation must start proposed.');
$recommendation->accept('Prepare a draft for review.');
$recommendation->materialize(2,'Draft revision created.');
expectGrowthV0170($recommendation->status()===OptimizationRecommendationStatus::Materialized,'Optimization recommendation materialization failed.');
expectGrowthV0170($recommendation->materializedRevision()===2,'Materialized revision reference is wrong.');

$schema=GrowthOptimizationPrompt::schema();
expectGrowthV0170(($schema['additionalProperties']??true)===false,'Optimization schema must reject undeclared top-level fields.');
expectGrowthV0170(in_array('evidence_ids',$schema['required']??[],true),'Optimization schema must require evidence ids.');
expectGrowthV0170(($schema['properties']['target_type']['enum']??[])===OptimizationTargetType::values(),'Optimization target vocabulary drifted.');

$client=new class implements StructuredLlmClientInterface {
    public ?StructuredLlmRequest $request=null;
    public function complete(StructuredLlmRequest $request):StructuredLlmResponse
    {
        $this->request=$request;
        return new StructuredLlmResponse([
            'target_type'=>'icp_profile',
            'target_id'=>'icp-1',
            'base_revision'=>1,
            'proposed_name'=>'ICP learned',
            'proposed_criteria'=>[
                'industries'=>['saas'],'regions'=>[],'min_employees'=>null,'max_employees'=>null,
                'technologies'=>[],'required_signal_types'=>['leadership_change'],
            ],
            'rationale'=>'Observed outcomes support a conservative signal requirement.',
            'evidence_ids'=>['learning:signal:abc'],
            'risks'=>['Small sample'],
            'assumptions'=>['Correlation is not causation'],
            'confidence'=>0.71,
        ],'fixture-provider','fixture-model',120,55,0.02,'USD');
    }
};
$gateway=new StructuredLlmGrowthOptimizationGateway($client);
$draft=$gateway->recommend('org-1','corr-1',[
    'active_targets'=>[
        'icp_profiles'=>[[
            'target_type'=>'icp_profile','target_id'=>'icp-1','revision'=>1,'name'=>'ICP',
            'criteria'=>[
                'industries'=>['saas'],'regions'=>[],'min_employees'=>null,'max_employees'=>null,
                'technologies'=>[],'required_signal_types'=>[],
            ],
        ]],
        'qualification_policies'=>[],
    ],
    'allowed_evidence_ids'=>['learning:signal:abc'],
]);
expectGrowthV0170($draft->targetType===OptimizationTargetType::IcpProfile,'Optimization gateway target mapping failed.');
expectGrowthV0170($client->request?->useCase==='growth.learning.optimize','Optimization gateway must use governed LLM use-case metadata.');
expectGrowthV0170($client->request?->organizationId==='org-1','Optimization gateway must propagate tenant context.');

echo "Growth V0.17 Learning Optimization contracts passed.\n";
