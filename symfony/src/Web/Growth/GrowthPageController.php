<?php
declare(strict_types=1);

namespace App\Web\Growth;

use App\Security\SessionCsrfValidator;
use App\Application\Growth\ReadModel\GrowthSignalPollingStatusProvider;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\ProviderBackedShellNavigation;
use App\Web\Experience\Shell\ShellNavigationItem;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeBoundary;
use Domains\Growth\Application\Contract\GrowthCollectorAlertBoundary;
use Domains\Growth\Application\Contract\GrowthDecisionBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementResponseBoundary;
use Domains\Growth\Application\Contract\GrowthConversationRoutingBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementLimitBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementActivationBoundary;
use Domains\Growth\Application\Contract\GrowthAutonomousOutreachBoundary;
use Domains\Growth\Application\Contract\GrowthAutonomousContentBoundary;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceBoundary;
use Domains\Growth\Application\Contract\GrowthExperimentBoundary;
use Domains\Growth\Application\Contract\GrowthHandoffBoundary;
use Domains\Growth\Application\Contract\GrowthIntelligenceBoundary;
use Domains\Growth\Application\Contract\GrowthLearningBoundary;
use Domains\Growth\Application\Contract\GrowthOptimizationBoundary;
use Domains\Growth\Application\Contract\GrowthResearchBoundary;
use Domains\Growth\Application\Contract\GrowthSignalCollectorBoundary;
use Domains\Growth\Application\Contract\GrowthSignalFeedBoundary;
use Domains\Growth\Application\Contract\GrowthJsonSignalSourceBoundary;
use Domains\Growth\Application\Contract\GrowthMarketDiscoveryBoundary;
use Domains\Growth\Application\Contract\GrowthWorkspaceReadModelInterface;
use Domains\Growth\Domain\GrowthExperimentDimension;
use Domains\Growth\Domain\GrowthExperimentStatus;
use Domains\Growth\Domain\GrowthOutcomeType;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class GrowthPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private ProviderBackedShellNavigation $shell,
        private SessionCsrfValidator $csrf,
        private GrowthWorkspaceReadModelInterface $workspace,
        private GrowthApplicationBoundary $growth,
        private GrowthIntelligenceBoundary $intelligence,
        private GrowthBuyingCommitteeBoundary $committee,
        private GrowthResearchBoundary $research,
        private GrowthSignalCollectorBoundary $collectors,
        private GrowthSignalFeedBoundary $signalFeeds,
        private GrowthCollectorAlertBoundary $collectorAlerts,
        private GrowthJsonSignalSourceBoundary $jsonSignalSources,
        private GrowthMarketDiscoveryBoundary $marketDiscovery,
        private GrowthSignalPollingStatusProvider $pollingStatus,
        private GrowthDecisionBoundary $decisions,
        private GrowthEngagementBoundary $engagement,
        private GrowthEngagementExecutionBoundary $engagementExecution,
        private GrowthEngagementResponseBoundary $engagementResponses,
        private GrowthConversationRoutingBoundary $conversationRouting,
        private GrowthEngagementLimitBoundary $engagementLimits,
        private GrowthEngagementActivationBoundary $engagementActivation,
        private GrowthAutonomousOutreachBoundary $autonomousOutreach,
        private GrowthAutonomousContentBoundary $autonomousContent,
        private GrowthOutreachSequenceBoundary $outreachSequences,
        private GrowthExperimentBoundary $experiments,
        private GrowthLearningBoundary $learning,
        private GrowthOptimizationBoundary $optimization,
        private GrowthHandoffBoundary $handoff,
    ) {}

    public function overview(Request $request): Response
    {
        return $this->page($request,'Growth Overview','growth-overview','growth/dashboard',
            fn(TenantContext $tenant):array=>[
                'workspace'=>$this->workspace->overview($tenant->organizationId()->value()),
            ]);
    }

    public function market(Request $request):Response
    {
        return $this->page($request,'Growth Market Discovery','growth-market','growth/market',
            fn(TenantContext $tenant):array=>[
                'workspace'=>[
                    'universes'=>$this->marketDiscovery->universes($tenant->organizationId()->value()),
                ],
            ]);
    }

    public function settings(Request $request):Response
    {
        return $this->page($request,'Growth Settings','growth-settings','growth/settings',
            fn(TenantContext $tenant):array=>[
                'workspace'=>[
                    'engagement_limits'=>$this->engagementLimits->view($tenant->organizationId()->value()),
                    'engagement_activation'=>$this->engagementActivation->view($tenant->organizationId()->value()),
                    'engagement_autonomy'=>$this->autonomousOutreach->viewPolicy($tenant->organizationId()->value()),
                    'engagement_content_review'=>$this->autonomousContent->viewReviewPolicy($tenant->organizationId()->value()),
                    'engagement_sequence_policy'=>$this->outreachSequences->viewPolicy($tenant->organizationId()->value()),
                ],
            ]);
    }

    public function candidates(Request $request): Response
    {
        return $this->page($request,'Growth Opportunities','growth-candidates','growth/candidates',
            fn(TenantContext $tenant):array=>[
                'workspace'=>[
                    'candidates'=>$this->workspace->candidates($tenant->organizationId()->value(),[
                        'q'=>$request->query->get('q'),
                        'status'=>$request->query->get('status'),
                        'growth_mode'=>$request->query->get('growth_mode'),
                        'target_domain'=>$request->query->get('target_domain'),
                    ],150),
                ],
            ]);
    }

    public function candidate(Request $request,string $id): Response
    {
        return $this->page($request,'Growth Opportunity','growth-candidates','growth/candidate',
            function(TenantContext $tenant)use($id):array{
                $organizationId=$tenant->organizationId()->value();
                $candidate=$this->growth->viewCandidate($organizationId,$id);
                if($candidate===null)return ['notFound'=>true,'workspace'=>[]];

                $signals=[];
                foreach($candidate['signal_ids']??[] as $signalId){
                    if(!is_string($signalId))continue;
                    $signal=$this->growth->viewSignal($organizationId,$signalId);
                    if($signal!==null)$signals[]=$signal;
                }

                $engagement=$this->engagement->engagementBrief($organizationId,$id);
                $execution=null;
                $autonomy=null;
                $content=null;
                $recommendation=$engagement['latest_recommendation']??null;
                if(is_array($recommendation)){
                    $recommendationId=$recommendation['recommendation_id']??null;
                    if(is_string($recommendationId)&&$recommendationId!==''){
                        $execution=$this->engagementExecution->executionBrief($organizationId,$id,$recommendationId);
                        $autonomy=$this->autonomousOutreach->recommendationBrief($organizationId,$id,$recommendationId);
                        $content=$this->autonomousContent->contentBrief($organizationId,$id,$recommendationId);
                    }
                }

                return [
                    'workspace'=>[
                        'candidate'=>$candidate,
                        'signals'=>$signals,
                        'research'=>$this->research->researchBrief($organizationId,$id),
                        'decision'=>$this->decisions->decisionBrief($organizationId,$id),
                        'engagement'=>$engagement,
                        'engagement_execution'=>$execution,
                        'engagement_autonomy'=>$autonomy,
                        'engagement_content'=>$content,
                        'engagement_responses'=>$this->engagementResponses->responseBrief($organizationId,$id),
                        'conversation_routing'=>$this->conversationRouting->routingBrief($organizationId,$id),
                        'engagement_sequence'=>$this->outreachSequences->sequenceBrief($organizationId,$id),
                        'handoff'=>$this->handoff->handoffBrief($organizationId,$id),
                        'learning'=>$this->learning->learningBrief($organizationId,$id),
                    ],
                ];
            });
    }

    public function accounts(Request $request): Response
    {
        return $this->page($request,'Growth Accounts','growth-accounts','growth/accounts',
            fn(TenantContext $tenant):array=>[
                'workspace'=>[
                    'accounts'=>$this->workspace->accounts($tenant->organizationId()->value(),[
                        'q'=>$request->query->get('q'),
                    ],150),
                ],
            ]);
    }

    public function signals(Request $request): Response
    {
        return $this->page($request,'Growth Signals','growth-signals','growth/signals',
            fn(TenantContext $tenant):array=>[
                'workspace'=>[
                    'signals'=>$this->workspace->signals($tenant->organizationId()->value(),[
                        'q'=>$request->query->get('q'),
                        'signal_type'=>$request->query->get('signal_type'),
                        'subject_type'=>$request->query->get('subject_type'),
                    ],150),
                ],
            ]);
    }

    public function learning(Request $request): Response
    {
        return $this->page($request,'Growth Learning','growth-learning','growth/learning',
            fn(TenantContext $tenant):array=>[
                'workspace'=>[
                    'learning'=>$this->workspace->learningOverview($tenant->organizationId()->value()),
                    'optimization'=>$this->optimization->optimizationBrief($tenant->organizationId()->value()),
                    'outcomes'=>$this->workspace->outcomes($tenant->organizationId()->value(),[
                        'q'=>$request->query->get('q'),
                        'outcome_type'=>$request->query->get('outcome_type'),
                        'currency'=>$request->query->get('currency'),
                    ],200),
                ],
            ]);
    }

    public function collectors(Request $request): Response
    {
        return $this->page($request,'Growth Collectors','growth-collectors','growth/collectors',
            fn(TenantContext $tenant):array=>[
                'workspace'=>[
                    'collectors'=>$this->collectors->collectors(),
                    'feeds'=>$this->signalFeeds->feeds($tenant->organizationId()->value()),
                    'alert_subscriptions'=>$this->collectorAlerts->subscriptions($tenant->organizationId()->value()),
                    'json_sources'=>$this->jsonSignalSources->sources($tenant->organizationId()->value()),
                    'polling'=>$this->pollingStatus->status($tenant->organizationId()->value()),
                    'runs'=>$this->workspace->collectorRuns($tenant->organizationId()->value(),[
                        'collector_name'=>$request->query->get('collector_name'),
                        'status'=>$request->query->get('status'),
                    ],150),
                ],
            ]);
    }

    public function experiments(Request $request): Response
    {
        return $this->page($request,'Growth Experiments','growth-experiments','growth/experiments',
            fn(TenantContext $tenant):array=>[
                'workspace'=>[
                    'experiments'=>$this->experiments->experiments($tenant->organizationId()->value(),[
                        'q'=>$request->query->get('q'),
                        'status'=>$request->query->get('status'),
                        'dimension'=>$request->query->get('dimension'),
                    ],200),
                    'dimensions'=>GrowthExperimentDimension::values(),
                    'outcomes'=>GrowthOutcomeType::values(),
                    'statuses'=>array_map(static fn(GrowthExperimentStatus $status):string=>$status->value,GrowthExperimentStatus::cases()),
                ],
            ]);
    }

    public function experiment(Request $request,string $id): Response
    {
        return $this->page($request,'Growth Experiment','growth-experiments','growth/experiment',
            function(TenantContext $tenant)use($id):array{
                try{
                    $brief=$this->experiments->experimentBrief($tenant->organizationId()->value(),$id);
                }catch(InvalidArgumentException){
                    return ['notFound'=>true,'workspace'=>[]];
                }

                return [
                    'workspace'=>[
                        'brief'=>$brief,
                        'statuses'=>array_map(static fn(GrowthExperimentStatus $status):string=>$status->value,GrowthExperimentStatus::cases()),
                    ],
                ];
            });
    }

    public function account(Request $request,string $id): Response
    {
        return $this->page($request,'Growth Account','growth-accounts','growth/account',
            function(TenantContext $tenant)use($id):array{
                $organizationId=$tenant->organizationId()->value();
                try{
                    $account=$this->intelligence->accountBrief($organizationId,$id);
                }catch(InvalidArgumentException){
                    return ['notFound'=>true,'workspace'=>[]];
                }

                $committee=[];
                try{$committee=$this->committee->buyingCommitteeBrief($organizationId,$id);}
                catch(Throwable){}

                return [
                    'workspace'=>[
                        'account'=>$account,
                        'committee'=>$committee,
                        'candidates'=>$this->workspace->candidates($organizationId,[
                            'subject_type'=>'account',
                            'subject_id'=>$id,
                        ],100),
                    ],
                ];
            });
    }

    /** @param callable(TenantContext):array<string,mixed> $reader */
    private function page(Request $request,string $title,string $active,string $view,callable $reader): Response
    {
        $tenant=$this->manager();
        if($tenant instanceof Response)return $tenant;

        try{
            $extra=$reader($tenant);
            if(!empty($extra['notFound'])){
                return $this->render($request,$tenant,'Not found',$active,'error/failure',[
                    'failureCode'=>404,
                    'failureTitle'=>'Growth entity not found',
                    'failureMessage'=>'The requested Growth entity does not exist in this organization.',
                    'failureRequestId'=>'TN-'.strtoupper(bin2hex(random_bytes(5))),
                    'failureActionUrl'=>'/growth',
                    'failureActionLabel'=>'Back to Growth',
                ],Response::HTTP_NOT_FOUND);
            }
            return $this->render($request,$tenant,$title,$active,$view,$extra);
        }catch(Throwable $error){
            error_log(sprintf('growth.workspace.read_failed [%s] %s',$view,$error->getMessage()));
            return $this->render($request,$tenant,'Growth temporarily unavailable',$active,'error/failure',[
                'failureCode'=>503,
                'failureTitle'=>'Growth workspace temporarily unavailable',
                'failureMessage'=>'The Growth read model could not be loaded. Details were written to the application log.',
                'failureRequestId'=>'TN-'.strtoupper(bin2hex(random_bytes(5))),
                'failureActionUrl'=>'/growth',
                'failureActionLabel'=>'Retry Growth',
            ],Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return new RedirectResponse('/auth/login');
        if(!$tenant->isManager())return new Response('Forbidden',Response::HTTP_FORBIDDEN);
        if(!$this->modules->isEnabled($tenant->organizationId()->value(),'growth')){
            return new Response('Growth module is disabled.',Response::HTTP_FORBIDDEN);
        }
        return $tenant;
    }

    /** @param array<string,mixed> $extra */
    private function render(
        Request $request,TenantContext $tenant,string $title,string $active,string $view,array $extra=[],int $status=200
    ): Response {
        $role=$tenant->role()->value();
        $context=new WebExtensionContext(
            $tenant->organizationId()->value(),$role,'workspace','growth',$active,
        );
        $navigation=$this->navigation($this->shell->compose($context));

        $variables=array_replace([
            'title'=>$title,
            'metaTitle'=>$title.' | Terra Nova COS',
            'metaRobots'=>'noindex,nofollow',
            'interfaceSurface'=>'workspace',
            'workspaceSection'=>'growth',
            'workspaceActive'=>$active,
            'workspaceActiveSection'=>'growth',
            'pageAssetEntries'=>['growth-workspace'],
            'csrfToken'=>$this->csrf->token($request),
            'currentUser'=>['id'=>(int)$tenant->userId()->value(),'role'=>$role],
            'role'=>$role,
            'isTeam'=>true,
            'isAdmin'=>$tenant->isAdmin(),
            'canManageGrowth'=>$tenant->allows(TenantPermissions::MANAGE),
            'workspaceNavigation'=>$navigation,
        ],$extra);

        return new Response(
            $this->renderer->render($request,$view,$variables),
            $status,
            ['Content-Type'=>'text/html; charset=UTF-8'],
        );
    }

    /** @param array{primary:list<ShellNavigationItem>,utility:list<ShellNavigationItem>,commands:array} $navigation */
    private function navigation(array $navigation): array
    {
        return [
            'surface'=>'workspace',
            'primary'=>array_map($this->navigationItem(...),$navigation['primary']),
            'utility'=>array_map($this->navigationItem(...),$navigation['utility']),
        ];
    }

    /** @return array<string,mixed> */
    private function navigationItem(ShellNavigationItem $item): array
    {
        return [
            'key'=>$item->key,
            'label'=>$item->label,
            'path'=>ltrim($item->path,'/'),
            'glyph'=>$item->glyph,
            'children'=>array_map($this->navigationItem(...),$item->children),
        ];
    }
}
