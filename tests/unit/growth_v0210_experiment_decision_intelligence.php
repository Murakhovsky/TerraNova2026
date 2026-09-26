<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use DomainException;
use Domains\Growth\Application\AI\GrowthExperimentDecisionPrompt;
use Domains\Growth\Domain\ExperimentDecisionRecommendation;
use Domains\Growth\Domain\ExperimentDecisionRecommendationStatus;
use Domains\Growth\Domain\ExperimentDecisionType;
use Domains\Growth\Infrastructure\AI\StructuredLlmGrowthExperimentDecisionGateway;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;
use Kernel\Llm\StructuredLlmResponse;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV0210(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$recommendation=new ExperimentDecisionRecommendation(
    'GEDC-1',OrganizationId::fromString('org-1'),'GEXP-1',
    ExperimentDecisionType::PromoteVariant,'b',
    'Variant B has the strongest observed primary outcome in the frozen attribution window.',
    ['experiment:variant:abc','experiment:overall:def'],
    ['Sample remains modest.'],['Observed association is not causality.'],0.78,
    'fixture-provider','fixture-model',
    GrowthExperimentDecisionPrompt::PROMPT_VERSION,GrowthExperimentDecisionPrompt::SCHEMA_VERSION,
    new DateTimeImmutable('2026-09-23T14:00:00+00:00'),
);
expectGrowthV0210($recommendation->status()===ExperimentDecisionRecommendationStatus::Proposed,'New experiment decision must be proposed.');
$recommendation->accept('Approved as the experiment conclusion.');
expectGrowthV0210($recommendation->status()===ExperimentDecisionRecommendationStatus::Accepted,'Experiment decision acceptance failed.');
try{
    $recommendation->dismiss('Too late');
    throw new RuntimeException('Decided experiment recommendation must not be decided twice.');
}catch(DomainException){}

try{
    new ExperimentDecisionRecommendation(
        'GEDC-BAD',OrganizationId::fromString('org-1'),'GEXP-1',
        ExperimentDecisionType::Continue,'a','Invalid variant selection.',
        ['evidence-1'],[],[],0.5,'fixture','model','prompt','schema',new DateTimeImmutable(),
    );
    throw new RuntimeException('Non-promote experiment decision must reject promoted variant.');
}catch(InvalidArgumentException){}

$schema=GrowthExperimentDecisionPrompt::schema();
expectGrowthV0210(($schema['additionalProperties']??true)===false,'Experiment decision schema must reject undeclared fields.');
expectGrowthV0210(
    ($schema['properties']['decision_type']['enum']??[])===ExperimentDecisionType::values(),
    'Experiment decision vocabulary drifted.'
);
expectGrowthV0210(
    in_array('evidence_ids',$schema['required']??[],true),
    'Experiment decision schema must require evidence ids.'
);

$client=new class implements StructuredLlmClientInterface {
    public ?StructuredLlmRequest $request=null;
    public function complete(StructuredLlmRequest $request):StructuredLlmResponse
    {
        $this->request=$request;
        return new StructuredLlmResponse([
            'decision_type'=>'inconclusive',
            'promoted_variant_key'=>null,
            'rationale'=>'Observed rates do not support a stable promotion recommendation.',
            'evidence_ids'=>['experiment:overall:fixture'],
            'risks'=>['Sparse sample'],
            'assumptions'=>['Attribution window is representative'],
            'confidence'=>0.42,
        ],'fixture-provider','fixture-model',120,50,0.02,'USD');
    }
};

$gateway=new StructuredLlmGrowthExperimentDecisionGateway($client);
$draft=$gateway->recommend('org-1','GEXP-1','corr-1',[
    'allowed_evidence_ids'=>['experiment:overall:fixture'],
    'allowed_variant_keys'=>['a','b'],
]);
expectGrowthV0210($draft->decisionType===ExperimentDecisionType::Inconclusive,'Experiment decision gateway mapping failed.');
expectGrowthV0210($draft->promotedVariantKey===null,'Inconclusive experiment decision must not select a variant.');
expectGrowthV0210($client->request?->useCase==='growth.experiment.decision','Experiment decision gateway must use governed use-case metadata.');
expectGrowthV0210($client->request?->organizationId==='org-1','Experiment decision gateway must propagate tenant context.');
expectGrowthV0210(($client->request?->responseSchema['additionalProperties']??true)===false,'Experiment decision gateway must use strict schema.');

echo "Growth V0.21 Experiment Decision Intelligence contracts passed.\n";
