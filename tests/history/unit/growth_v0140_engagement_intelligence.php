<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use DomainException;
use Domains\Growth\Application\AI\GrowthEngagementPrompt;
use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\EngagementRecommendation;
use Domains\Growth\Domain\EngagementRecommendationStatus;
use Domains\Growth\Domain\NextBestActionType;
use Domains\Growth\Infrastructure\AI\StructuredLlmGrowthEngagementGateway;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;
use Kernel\Llm\StructuredLlmResponse;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV0140(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

expectGrowthV0140(NextBestActionType::SendEmail->allowsChannel(EngagementChannel::Email),'Send email must allow email channel.');
expectGrowthV0140(!NextBestActionType::SendEmail->allowsChannel(EngagementChannel::Phone),'Send email must reject phone channel.');
expectGrowthV0140(NextBestActionType::Monitor->allowsChannel(EngagementChannel::None),'Monitor must use none channel.');
expectGrowthV0140(NextBestActionType::CreateReport->allowsChannel(EngagementChannel::Internal),'Create report must use internal channel.');

$recommendation=new EngagementRecommendation(
    'GERC-1',OrganizationId::fromString('org-1'),'candidate-1',
    NextBestActionType::SendEmail,EngagementChannel::Email,'contact-1',
    'Current evidence makes a diagnostic offer timely.','Lead with operational coordination risk.',
    ['signal-1'],['Budget authority is unknown'],0.82,
    'fixture-provider','fixture-model',GrowthEngagementPrompt::PROMPT_VERSION,GrowthEngagementPrompt::SCHEMA_VERSION,
    new DateTimeImmutable('2026-09-22T14:00:00+00:00'),
);
expectGrowthV0140($recommendation->status()===EngagementRecommendationStatus::Proposed,'New engagement recommendation must be proposed.');
$recommendation->accept('Approved for human outreach planning.');
expectGrowthV0140($recommendation->status()===EngagementRecommendationStatus::Accepted,'Engagement recommendation acceptance failed.');
try{
    $recommendation->dismiss('Too late');
    throw new RuntimeException('Decided recommendation must not be decided twice.');
}catch(DomainException){}

try{
    new EngagementRecommendation(
        'GERC-BAD',OrganizationId::fromString('org-1'),'candidate-1',
        NextBestActionType::SendEmail,EngagementChannel::Phone,'contact-1',
        'Invalid channel fixture','Fixture',['signal-1'],[],0.8,
        'fixture','model','prompt','schema',new DateTimeImmutable(),
    );
    throw new RuntimeException('Invalid engagement action/channel combination must fail.');
}catch(InvalidArgumentException){}

$schema=GrowthEngagementPrompt::schema();
expectGrowthV0140(($schema['additionalProperties']??true)===false,'Growth engagement schema must reject undeclared fields.');
expectGrowthV0140(in_array('evidence_ids',$schema['required']??[],true),'Growth engagement schema must require evidence ids.');
expectGrowthV0140(($schema['properties']['action_type']['enum']??[])===NextBestActionType::values(),'Growth engagement action vocabulary drifted.');

$client=new class implements StructuredLlmClientInterface {
    public ?StructuredLlmRequest $request=null;
    public function complete(StructuredLlmRequest $request):StructuredLlmResponse
    {
        $this->request=$request;
        return new StructuredLlmResponse([
            'action_type'=>'send_email',
            'channel'=>'email',
            'contact_id'=>'contact-1',
            'rationale'=>'Recent evidence makes contact timely.',
            'message_angle'=>'Lead with the verified operating-change signal.',
            'evidence_ids'=>['signal-1'],
            'unknowns'=>['Budget'],
            'confidence'=>0.79,
        ],'fixture-provider','fixture-model',100,40,0.01,'USD');
    }
};
$gateway=new StructuredLlmGrowthEngagementGateway($client);
$draft=$gateway->recommend('org-1','candidate-1','corr-1',[
    'allowed_evidence_ids'=>['signal-1'],
    'allowed_contact_ids'=>['contact-1'],
]);
expectGrowthV0140($draft->actionType===NextBestActionType::SendEmail&&$draft->channel===EngagementChannel::Email,'Growth engagement gateway mapping failed.');
expectGrowthV0140($draft->contactId==='contact-1','Growth engagement gateway lost contact id.');
expectGrowthV0140($client->request?->useCase==='growth.engagement.recommend','Growth engagement gateway must use governed use-case metadata.');
expectGrowthV0140($client->request?->organizationId==='org-1','Growth engagement gateway must propagate tenant context.');
expectGrowthV0140(($client->request?->responseSchema['additionalProperties']??true)===false,'Growth engagement gateway must use strict schema.');

echo "Growth V0.14 Engagement Intelligence contracts passed.\n";
