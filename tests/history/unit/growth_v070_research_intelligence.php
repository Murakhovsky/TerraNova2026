<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\AI\GrowthResearchPrompt;
use Domains\Growth\Application\DTO\ResearchProposalDraft;
use Domains\Growth\Domain\ResearchProposal;
use Domains\Growth\Infrastructure\AI\StructuredLlmGrowthResearchGateway;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;
use Kernel\Llm\StructuredLlmResponse;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV070(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$draft=new ResearchProposalDraft(
    'Scaling creates operational risk',
    'The company may need a more controlled operating layer',
    'A new COO joined while RevOps hiring increased',
    ['signal-1','signal-2'],
    ['signal-3'],
    ['Budget authority may sit with COO'],
    ['Budget','Current process maturity'],
    0.78,
    'fixture-provider','fixture-model',
    GrowthResearchPrompt::PROMPT_VERSION,GrowthResearchPrompt::SCHEMA_VERSION,
    1200,300,0.02,'USD',
);
$proposal=new ResearchProposal(
    'proposal-1',OrganizationId::fromString('org-1'),'candidate-1',
    $draft->whyItMatters,$draft->problemHypothesis,$draft->whyNow,
    $draft->evidenceIds,$draft->counterEvidenceIds,$draft->assumptions,$draft->unknowns,
    $draft->confidence,$draft->provider,$draft->model,$draft->promptVersion,$draft->schemaVersion,
    new DateTimeImmutable('2026-09-22T10:00:00+00:00'),
);
$rationale=$proposal->rationale();

expectGrowthV070($rationale->whyNow===$draft->whyNow,'Growth research proposal must preserve WHY NOW.');
expectGrowthV070($rationale->evidenceIds===['signal-1','signal-2'],'Growth research evidence ids were not preserved.');
expectGrowthV070($rationale->counterEvidenceIds===['signal-3'],'Growth research counter evidence was not preserved.');
expectGrowthV070($rationale->confidence===0.78,'Growth research confidence was not preserved.');
expectGrowthV070(GrowthResearchPrompt::PROMPT_VERSION==='growth-research-v1','Growth research prompt version must be explicit.');
expectGrowthV070(GrowthResearchPrompt::SCHEMA_VERSION==='growth-research-schema-v1','Growth research schema version must be explicit.');
$schema=GrowthResearchPrompt::schema();
expectGrowthV070(in_array('evidence_ids',$schema['required']??[],true),'Growth research schema must require evidence ids.');
expectGrowthV070(($schema['additionalProperties']??true)===false,'Growth research schema must reject undeclared fields.');

$client=new class implements StructuredLlmClientInterface {
    public ?StructuredLlmRequest $request=null;
    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        $this->request=$request;
        return new StructuredLlmResponse([
            'why_it_matters'=>'Evidence suggests operational scaling pressure',
            'problem_hypothesis'=>'Process coordination may be insufficient',
            'why_now'=>'Leadership change and hiring are current',
            'evidence_ids'=>['signal-1'],
            'counter_evidence_ids'=>[],
            'assumptions'=>['Buying process unknown'],
            'unknowns'=>['Budget'],
            'confidence'=>0.72,
        ],'fixture-provider','fixture-model',100,50,0.01,'USD');
    }
};
$gateway=new StructuredLlmGrowthResearchGateway($client);
$generated=$gateway->propose('org-1','candidate-1','corr-1',[
    'allowed_evidence_ids'=>['signal-1'],
    'signals'=>[['signal_id'=>'signal-1']],
]);
expectGrowthV070($generated->provider==='fixture-provider'&&$generated->model==='fixture-model','Growth research gateway must preserve provider/model metadata.');
expectGrowthV070($client->request?->useCase==='growth.research.propose','Growth research gateway must use governed use-case metadata.');
expectGrowthV070($client->request?->organizationId==='org-1','Growth research gateway must propagate tenant context.');
expectGrowthV070(($client->request?->responseSchema['additionalProperties']??true)===false,'Growth research gateway must use strict response schema.');

echo "Growth V0.7 Research Intelligence contracts passed.\n";
