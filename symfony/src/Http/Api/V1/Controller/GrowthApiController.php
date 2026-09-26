<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Security\SessionCsrfValidator;
use DomainException;
use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeBoundary;
use Domains\Growth\Application\Contract\GrowthCollectorAlertBoundary;
use Domains\Growth\Application\Contract\GrowthDecisionBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementLimitBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementActivationBoundary;
use Domains\Growth\Application\Contract\GrowthAutonomousOutreachBoundary;
use Domains\Growth\Application\Contract\GrowthAutonomousContentBoundary;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceBoundary;
use Domains\Growth\Application\Contract\GrowthExperimentBoundary;
use Domains\Growth\Application\Contract\GrowthExperimentDecisionBoundary;
use Domains\Growth\Application\Contract\GrowthHandoffBoundary;
use Domains\Growth\Application\Contract\GrowthIntelligenceBoundary;
use Domains\Growth\Application\Contract\GrowthLearningBoundary;
use Domains\Growth\Application\Contract\GrowthOptimizationBoundary;
use Domains\Growth\Application\Contract\GrowthResearchBoundary;
use Domains\Growth\Application\Contract\GrowthSignalCollectorBoundary;
use Domains\Growth\Application\Contract\GrowthSignalFeedBoundary;
use Domains\Growth\Application\Contract\GrowthJsonSignalSourceBoundary;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class GrowthApiController
{
    public function __construct(
        private GrowthApplicationBoundary $growth,
        private GrowthSignalCollectorBoundary $collectors,
        private GrowthSignalFeedBoundary $signalFeeds,
        private GrowthCollectorAlertBoundary $collectorAlerts,
        private GrowthJsonSignalSourceBoundary $jsonSignalSources,
        private GrowthIntelligenceBoundary $intelligence,
        private GrowthBuyingCommitteeBoundary $committee,
        private GrowthResearchBoundary $research,
        private GrowthDecisionBoundary $decisions,
        private GrowthEngagementBoundary $engagement,
        private GrowthEngagementExecutionBoundary $engagementExecution,
        private GrowthEngagementLimitBoundary $engagementLimits,
        private GrowthEngagementActivationBoundary $engagementActivation,
        private GrowthAutonomousOutreachBoundary $autonomousOutreach,
        private GrowthAutonomousContentBoundary $autonomousContent,
        private GrowthOutreachSequenceBoundary $outreachSequences,
        private GrowthLearningBoundary $learning,
        private GrowthOptimizationBoundary $optimization,
        private GrowthHandoffBoundary $handoff,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
    ) {}

    public function engagementLimits():JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->engagementLimits->view($tenant->organizationId()->value()));
    }

    public function updateEngagementLimits(Request $request):JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->engagementLimits->update(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ));
    }

    public function engagementActivation():JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->engagementActivation->view($tenant->organizationId()->value()));
    }

    public function updateEngagementActivation(Request $request):JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->engagementActivation->update(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ));
    }

    public function engagementAutonomy():JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->autonomousOutreach->viewPolicy($tenant->organizationId()->value()));
    }

    public function updateEngagementAutonomy(Request $request):JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->autonomousOutreach->updatePolicy(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ));
    }

    public function engagementContentReview():JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->autonomousContent->viewReviewPolicy($tenant->organizationId()->value()));
    }

    public function updateEngagementContentReview(Request $request):JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->autonomousContent->updateReviewPolicy(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ));
    }

    public function engagementSequencePolicy():JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->outreachSequences->viewPolicy($tenant->organizationId()->value()));
    }

    public function updateEngagementSequencePolicy(Request $request):JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->outreachSequences->updatePolicy(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ));
    }

    public function collectors(): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>['collectors'=>$this->collectors->collectors()]);
    }

    public function runCollector(Request $request,string $name): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$name):array{
            $input=$this->input($request);
            $cursor=$input['cursor']??null;
            if($cursor!==null&&(!is_string($cursor)||trim($cursor)==='')){
                throw new InvalidArgumentException('cursor must be null or a non-empty string.');
            }
            $limit=$input['limit']??100;
            if(!is_int($limit)&&!(is_string($limit)&&ctype_digit($limit))){
                throw new InvalidArgumentException('limit must be an integer.');
            }
            return $this->collectors->runCollector(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$name,$key,
                $cursor===null?null:trim($cursor),(int)$limit,
            );
        },202);
    }

    public function signalFeeds(): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>[
            'feeds'=>$this->signalFeeds->feeds($tenant->organizationId()->value()),
        ]);
    }

    public function createSignalFeed(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->signalFeeds->createFeed(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ),201);
    }

    public function enableSignalFeed(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->signalFeeds->setEnabled(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,true,$key
            ));
    }

    public function disableSignalFeed(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->signalFeeds->setEnabled(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,false,$key
            ));
    }

    public function collectorAlertSubscriptions(): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>[
            'subscriptions'=>$this->collectorAlerts->subscriptions($tenant->organizationId()->value()),
        ]);
    }

    public function createCollectorAlertSubscription(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->collectorAlerts->createSubscription(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ),201);
    }

    public function enableCollectorAlertSubscription(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->collectorAlerts->setEnabled(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,true,$key
            ));
    }

    public function disableCollectorAlertSubscription(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->collectorAlerts->setEnabled(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,false,$key
            ));
    }

    public function jsonSignalSources(): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>[
            'sources'=>$this->jsonSignalSources->sources($tenant->organizationId()->value()),
        ]);
    }

    public function createJsonSignalSource(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->jsonSignalSources->createSource(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ),201);
    }

    public function enableJsonSignalSource(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->jsonSignalSources->setEnabled(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,true,$key
            ));
    }

    public function disableJsonSignalSource(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->jsonSignalSources->setEnabled(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,false,$key
            ));
    }

    public function createSignal(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->growth->detectSignal($tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)),201);
    }

    public function signal(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->growth->viewSignal($tenant->organizationId()->value(),$id)
            ?? throw new InvalidArgumentException('Growth signal was not found.'));
    }

    public function createCandidate(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->growth->detectCandidate($tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)),201);
    }

    public function candidate(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->growth->viewCandidate($tenant->organizationId()->value(),$id)
            ?? throw new InvalidArgumentException('Growth candidate was not found.'));
    }

    public function researchCandidate(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->growth->researchCandidate($tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$key,$this->input($request)));
    }

    public function scoreCandidate(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->growth->scoreCandidate($tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$key,$this->input($request)));
    }

    public function qualifyCandidate(Request $request,string $id): JsonResponse
    {
        return $this->candidateReasonMutation($request,$id,'qualify');
    }

    public function monitorCandidate(Request $request,string $id): JsonResponse
    {
        return $this->candidateReasonMutation($request,$id,'monitor');
    }

    public function disqualifyCandidate(Request $request,string $id): JsonResponse
    {
        return $this->candidateReasonMutation($request,$id,'disqualify');
    }

    public function prepareHandoff(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->growth->prepareHandoff($tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$key,$this->input($request)));
    }

    public function createIcp(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->intelligence->createIcpProfile($tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)),201);
    }

    public function reviseIcp(Request $request,string $id,string $revision): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$revision):array{
            $baseRevision=$this->positiveInt($revision,'revision');
            return $this->intelligence->reviseIcpProfile(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$baseRevision,$key,$this->input($request)
            );
        },201);
    }

    public function activateIcp(Request $request,string $id,string $revision): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($id,$revision):array{
            $value=$this->positiveInt($revision,'revision');
            return $this->intelligence->activateIcpProfile(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$value,$key
            );
        });
    }

    public function createAccount(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->intelligence->discoverAccount($tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)),201);
    }

    public function account(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->intelligence->accountBrief($tenant->organizationId()->value(),$id));
    }

    public function snapshotAccount(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->intelligence->captureAccountSnapshot(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$key,$this->input($request)
            ),201);
    }

    public function scoreAccount(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id):array{
            $input=$this->input($request);
            $profileId=$this->requiredString($input,'profile_id');
            $revision=$this->positiveInt($input['revision']??null,'revision');
            return $this->intelligence->scoreAccount(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$profileId,$revision,$key
            );
        });
    }

    public function createContact(Request $request,string $accountId): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->committee->discoverContact(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$accountId,$key,$this->input($request)
            ),201);
    }

    public function snapshotContact(Request $request,string $accountId,string $contactId): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->committee->captureContactSnapshot(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$accountId,$contactId,$key,$this->input($request)
            ),201);
    }

    public function assessCommittee(Request $request,string $accountId): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$accountId):array{
            $input=$this->input($request);
            $roles=$input['required_roles']??null;
            if(!is_array($roles)||!array_is_list($roles))throw new InvalidArgumentException('required_roles must be a list.');
            $normalized=[];
            foreach($roles as $role){
                if(!is_string($role)||trim($role)==='')throw new InvalidArgumentException('required_roles contains an invalid value.');
                $normalized[]=trim($role);
            }
            return $this->committee->assessBuyingCommittee(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$accountId,$key,$normalized
            );
        });
    }

    public function committee(string $accountId): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->committee->buyingCommitteeBrief($tenant->organizationId()->value(),$accountId));
    }

    public function generateResearch(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->research->generateProposal($tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$key),202);
    }

    public function acceptResearch(Request $request,string $id,string $proposalId): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->research->acceptProposal(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$proposalId,$key
            ));
    }

    public function researchBrief(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->research->researchBrief($tenant->organizationId()->value(),$id));
    }

    public function createQualificationPolicy(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->decisions->createQualificationPolicy(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ),201);
    }

    public function reviseQualificationPolicy(Request $request,string $id,string $revision): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$revision):array{
            $baseRevision=$this->positiveInt($revision,'revision');
            return $this->decisions->reviseQualificationPolicy(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$baseRevision,$key,$this->input($request)
            );
        },201);
    }

    public function activateQualificationPolicy(Request $request,string $id,string $revision): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($id,$revision):array{
            $value=$this->positiveInt($revision,'revision');
            return $this->decisions->activateQualificationPolicy(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$value,$key
            );
        });
    }

    public function evaluateCandidate(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id):array{
            $input=$this->input($request);
            $policyId=$this->requiredString($input,'policy_id');
            $revision=$this->positiveInt($input['revision']??null,'revision');
            return $this->decisions->evaluateCandidate(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$policyId,$revision,$key
            );
        });
    }

    public function decisionBrief(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->decisions->decisionBrief($tenant->organizationId()->value(),$id));
    }

    public function generateEngagement(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->engagement->generateRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$key
            ),202);
    }

    public function acceptEngagement(Request $request,string $id,string $recommendationId): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$recommendationId):array{
            $reason=$this->requiredString($this->input($request),'reason');
            return $this->engagement->acceptRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$recommendationId,$reason,$key
            );
        });
    }

    public function dismissEngagement(Request $request,string $id,string $recommendationId): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$recommendationId):array{
            $reason=$this->requiredString($this->input($request),'reason');
            return $this->engagement->dismissRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$recommendationId,$reason,$key
            );
        });
    }

    public function engagementBrief(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->engagement->engagementBrief($tenant->organizationId()->value(),$id));
    }

    public function proposeEngagementExecution(Request $request,string $id,string $recommendationId): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$recommendationId):array{
            $body=$this->requiredString($this->input($request),'body');
            return $this->engagementExecution->proposeMessageAction(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,
                $id,$recommendationId,$body,$key
            );
        },202);
    }

    public function engagementExecution(string $id,string $recommendationId): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->engagementExecution->executionBrief(
                $tenant->organizationId()->value(),$id,$recommendationId
            ));
    }

    public function stageEngagementAutonomyPayload(Request $request,string $id,string $recommendationId):JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$recommendationId):array{
            $input=$this->input($request);
            return $this->autonomousOutreach->stagePayload(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$recommendationId,
                $this->requiredString($input,'body'),$this->requiredString($input,'reason'),$key,
            );
        });
    }

    public function engagementContentBrief(string $id,string $recommendationId):JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->autonomousContent->contentBrief($tenant->organizationId()->value(),$id,$recommendationId));
    }

    public function generateEngagementContentDraft(Request $request,string $id,string $recommendationId):JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->autonomousContent->generateDraft(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$recommendationId,$key,'USER'
            ),202);
    }

    public function approveEngagementContentDraft(Request $request,string $id,string $recommendationId,string $draftId):JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$recommendationId,$draftId):array{
            return $this->autonomousContent->approveDraft(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$recommendationId,$draftId,
                $this->requiredString($this->input($request),'reason'),$key
            );
        });
    }

    public function rejectEngagementContentDraft(Request $request,string $id,string $recommendationId,string $draftId):JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$recommendationId,$draftId):array{
            return $this->autonomousContent->rejectDraft(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$recommendationId,$draftId,
                $this->requiredString($this->input($request),'reason'),$key
            );
        });
    }

    public function engagementSequence(string $id):JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->outreachSequences->sequenceBrief($tenant->organizationId()->value(),$id));
    }

    public function stopEngagementSequence(Request $request,string $id,string $sequenceId):JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$sequenceId):array{
            return $this->outreachSequences->stopSequence(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$sequenceId,
                $this->requiredString($this->input($request),'reason'),$key
            );
        });
    }

    public function learningBrief(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->learning->learningBrief($tenant->organizationId()->value(),$id));
    }

    public function generateOptimization(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->optimization->generateRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key
            ),202);
    }

    public function optimizationBrief(): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->optimization->optimizationBrief($tenant->organizationId()->value()));
    }

    public function acceptOptimization(Request $request,string $recommendationId): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$recommendationId):array{
            $reason=$this->requiredString($this->input($request),'reason');
            return $this->optimization->acceptRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$recommendationId,$reason,$key
            );
        });
    }

    public function dismissOptimization(Request $request,string $recommendationId): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$recommendationId):array{
            $reason=$this->requiredString($this->input($request),'reason');
            return $this->optimization->dismissRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$recommendationId,$reason,$key
            );
        });
    }

    public function materializeOptimization(Request $request,string $recommendationId): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->optimization->materializeRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$recommendationId,$key
            ),201);
    }

    public function createExperiment(Request $request): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->experiments->createExperiment(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$key,$this->input($request)
            ),201);
    }

    public function experiments(Request $request): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>[
            'experiments'=>$this->experiments->experiments($tenant->organizationId()->value(),[
                'q'=>$request->query->get('q'),
                'status'=>$request->query->get('status'),
                'dimension'=>$request->query->get('dimension'),
            ],200),
        ]);
    }

    public function experiment(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->experiments->experimentBrief($tenant->organizationId()->value(),$id));
    }

    public function startExperiment(Request $request,string $id): JsonResponse
    {
        return $this->experimentTransition($request,$id,'start');
    }

    public function pauseExperiment(Request $request,string $id): JsonResponse
    {
        return $this->experimentTransition($request,$id,'pause');
    }

    public function resumeExperiment(Request $request,string $id): JsonResponse
    {
        return $this->experimentTransition($request,$id,'resume');
    }

    public function completeExperiment(Request $request,string $id): JsonResponse
    {
        return $this->experimentTransition($request,$id,'complete');
    }

    public function archiveExperiment(Request $request,string $id): JsonResponse
    {
        return $this->experimentTransition($request,$id,'archive');
    }

    public function assignExperimentCandidate(Request $request,string $id,string $candidateId): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$candidateId):array{
            $input=$this->input($request);
            $variant=$input['variant_key']??null;
            if($variant!==null&&!is_string($variant))throw new InvalidArgumentException('variant_key must be null or string.');
            return $this->experiments->assignCandidate(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$candidateId,
                $variant===null?null:trim($variant),$key,
            );
        },201);
    }

    public function experimentReport(string $id): JsonResponse
    {
        return $this->read(function(TenantContext $tenant)use($id):array{
            $brief=$this->experiments->experimentBrief($tenant->organizationId()->value(),$id);
            return [
                'experiment'=>$brief['experiment']??null,
                'attribution'=>$brief['attribution']??null,
            ];
        });
    }

    public function generateExperimentDecision(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->experimentDecisions->generateRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$key
            ),202);
    }

    public function acceptExperimentDecision(Request $request,string $id,string $recommendationId): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$recommendationId):array{
            $reason=$this->requiredString($this->input($request),'reason');
            return $this->experimentDecisions->acceptRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,
                $id,$recommendationId,$reason,$key
            );
        });
    }

    public function dismissExperimentDecision(Request $request,string $id,string $recommendationId): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$recommendationId):array{
            $reason=$this->requiredString($this->input($request),'reason');
            return $this->experimentDecisions->dismissRecommendation(
                $tenant->organizationId()->value(),$this->actor($tenant),$correlation,
                $id,$recommendationId,$reason,$key
            );
        });
    }

    public function experimentDecisionBrief(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->experimentDecisions->decisionBrief($tenant->organizationId()->value(),$id));
    }

    public function handoffTargets(): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>['targets'=>$this->handoff->targets()]);
    }

    public function handoffBrief(string $id): JsonResponse
    {
        return $this->read(fn(TenantContext $tenant):array=>
            $this->handoff->handoffBrief($tenant->organizationId()->value(),$id));
    }

    public function dispatchHandoff(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,fn(TenantContext $tenant,string $key,string $correlation):array=>
            $this->handoff->dispatch($tenant->organizationId()->value(),$this->actor($tenant),$correlation,$id,$key),202);
    }

    private function experimentTransition(Request $request,string $id,string $transition): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($id,$transition):array{
            $organizationId=$tenant->organizationId()->value();
            $actorId=$this->actor($tenant);
            return match($transition){
                'start'=>$this->experiments->startExperiment($organizationId,$actorId,$correlation,$id,$key),
                'pause'=>$this->experiments->pauseExperiment($organizationId,$actorId,$correlation,$id,$key),
                'resume'=>$this->experiments->resumeExperiment($organizationId,$actorId,$correlation,$id,$key),
                'complete'=>$this->experiments->completeExperiment($organizationId,$actorId,$correlation,$id,$key),
                'archive'=>$this->experiments->archiveExperiment($organizationId,$actorId,$correlation,$id,$key),
                default=>throw new InvalidArgumentException('Unsupported Growth experiment transition.'),
            };
        });
    }

    private function candidateReasonMutation(Request $request,string $id,string $operation): JsonResponse
    {
        return $this->mutate($request,function(TenantContext $tenant,string $key,string $correlation)use($request,$id,$operation):array{
            $reason=$this->requiredString($this->input($request),'reason');
            $organizationId=$tenant->organizationId()->value();
            $actorId=$this->actor($tenant);
            return match($operation){
                'qualify'=>$this->growth->qualifyCandidate($organizationId,$actorId,$correlation,$id,$reason,$key),
                'monitor'=>$this->growth->monitorCandidate($organizationId,$actorId,$correlation,$id,$reason,$key),
                'disqualify'=>$this->growth->disqualifyCandidate($organizationId,$actorId,$correlation,$id,$reason,$key),
                default=>throw new InvalidArgumentException('Unsupported Growth candidate reason mutation.'),
            };
        });
    }

    /** @param callable(TenantContext):array<string,mixed> $operation */
    private function read(callable $operation): JsonResponse
    {
        $context=$this->context(null,false);
        if($context instanceof JsonResponse)return $context;
        try{
            return $this->ok($operation($context));
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    /** @param callable(TenantContext,string,string):array<string,mixed> $operation */
    private function mutate(Request $request,callable $operation,int $successStatus=200): JsonResponse
    {
        $context=$this->context($request,true);
        if($context instanceof JsonResponse)return $context;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;

        try{
            return $this->ok($operation($context,$key,$this->correlationId($request)),$successStatus);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    private function context(?Request $request,bool $mutation): TenantContext|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error(403,'tenant_context_required','Tenant context required.');

        $permission=$mutation?TenantPermissions::MANAGE:TenantPermissions::ACCESS;
        if(!$tenant->allows($permission)){
            return $this->error(403,'growth_permission_denied','Required Growth tenant permission is missing.');
        }
        if(!$this->modules->isEnabled($tenant->organizationId()->value(),'growth')){
            return $this->error(403,'growth_module_disabled','Growth module is disabled for this organization.');
        }
        if($mutation&&!$this->csrf->isValid($request)){
            return $this->error(400,'invalid_csrf_token','Invalid CSRF token.');
        }
        if($mutation){
            $actor=$tenant->userId()->value();
            if(!ctype_digit($actor)||(int)$actor<=0){
                return $this->error(403,'invalid_actor','Authenticated actor is invalid.');
            }
        }

        return $tenant;
    }

    private function actor(TenantContext $tenant): int
    {
        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor)||(int)$actor<=0)throw new InvalidArgumentException('Authenticated Growth actor is invalid.');
        return (int)$actor;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $decoded=json_decode((string)$request->getContent(),true);
        return is_array($decoded)&&!array_is_list($decoded)?$decoded:$request->request->all();
    }

    private function idempotencyKey(Request $request): string|JsonResponse
    {
        $key=trim((string)$request->headers->get('X-Idempotency-Key',''));
        if($key===''||mb_strlen($key)>191){
            return $this->error(422,'idempotency_key_required','A valid X-Idempotency-Key is required.');
        }
        return $key;
    }

    private function correlationId(Request $request): string
    {
        $value=$request->attributes->get('_cos_correlation_id');
        return $value instanceof CorrelationId?$value->value():CorrelationId::generate()->value();
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input,string $key): string
    {
        $value=$input[$key]??null;
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException($key.' is required.');
        return trim($value);
    }

    private function positiveInt(mixed $value,string $field): int
    {
        if((!is_int($value)&&!(is_string($value)&&ctype_digit($value)))||(int)$value<1){
            throw new InvalidArgumentException($field.' must be a positive integer.');
        }
        return (int)$value;
    }

    private function failure(Throwable $error): JsonResponse
    {
        $message=trim($error->getMessage());
        $normalized=strtolower($message);
        $status=match(true){
            str_contains($normalized,'not found')=>404,
            str_contains($normalized,'idempotency')||str_contains($normalized,'conflict')=>409,
            $error instanceof DomainException=>409,
            $error instanceof InvalidArgumentException=>422,
            default=>500,
        };
        return $this->error($status,'growth_operation_failed',$message!==''?$message:'Growth operation failed.');
    }

    private function ok(mixed $data,int $status=200): JsonResponse
    {
        return new JsonResponse(['ok'=>true,'data'=>$data],$status);
    }

    private function error(int $status,string $code,string $message): JsonResponse
    {
        return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);
    }
}
