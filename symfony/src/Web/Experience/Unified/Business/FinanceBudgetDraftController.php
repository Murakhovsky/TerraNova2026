<?php
declare(strict_types=1);

namespace App\Web\Experience\Unified\Business;

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Action\UIActionResolver;
use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\ShellConnectionState;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use DateTimeImmutable;
use DomainException;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Twig\Environment;

/** Human-only internal DRAFT. No reservation, activation, payment or external dispatch. */
#[AsController]
final readonly class FinanceBudgetDraftController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private UIActionResolver $actions,
        private CanonicalCapabilityGateway $gateway,
        private WorkspaceCapabilityRead $read,
        private WorkspaceShellFactory $shells,
        private Environment $twig,
    ) {}

    public function draft(Request $request):Response
    {
        $actor=$this->tenants->current();
        if ($actor===null) return new RedirectResponse('/auth/login');
        if (!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)) {
            return new Response('Forbidden',403);
        }
        if ($request->isMethod('POST')&&!$this->csrf->isValid($request)) return new Response('Forbidden',403);
        $context=new WebExtensionContext($actor->organizationId()->value(),$actor->role()->value(),'workspace','workspaces','finance-budget-draft');
        try {
            $action=$this->actions->resolveOne('finance.budget.draft.create',$actor,$context,null,UIActionPlacement::WORKSPACE_PRIMARY);
            if (!$action->enabled||$action->command!=='finance.budget.envelope.draft.create') return new Response('Forbidden',403);
        } catch (\Throwable) {
            return new Response('Budget draft unavailable.',404);
        }
        $back=WorkspaceCapabilityRead::workspacePath('finance.budget');
        $shell=$this->shells->create($actor,$context,'Create budget draft',[
            new ShellBreadcrumb('Workspaces','/workspace/unified'),
            new ShellBreadcrumb('Budget envelopes',$back),
            new ShellBreadcrumb('Create budget draft'),
        ],aiAvailable:false,connectionState:ShellConnectionState::Stale);
        $form=[
            'name'=>'','source_ref'=>'','currency'=>'','period_start'=>'','period_end'=>'','limit_minor'=>'',
        ];
        $key='cuw-'.bin2hex(random_bytes(16));
        $error=null;$receipt=null;$status=200;
        if ($request->isMethod('POST')) {
            foreach (array_keys($form) as $field) $form[$field]=trim((string)$request->request->get($field,''));
            $key=(string)$request->request->get('idempotency_key','');
            $limit=filter_var($form['limit_minor'],FILTER_VALIDATE_INT);
            $start=preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$form['period_start'])?DateTimeImmutable::createFromFormat('!Y-m-d',$form['period_start']):false;
            $end=preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$form['period_end'])?DateTimeImmutable::createFromFormat('!Y-m-d',$form['period_end']):false;
            if (!preg_match('/^cuw-[a-f0-9]{32}$/D',$key)) return new Response('Invalid draft submission.',422);
            if (mb_strlen($form['name'])<3||mb_strlen($form['name'])>190
                ||!preg_match('/^mkt-[a-f0-9]{24}$/D',$form['source_ref'])
                ||!preg_match('/^[A-Z]{3}$/D',$form['currency'])
                ||!$start||!$end||$start->format('Y-m-d')!==$form['period_start']||$end->format('Y-m-d')!==$form['period_end']
                ||$start>$end||$start->diff($end)->days>366||$limit===false||$limit<1||$limit>1_000_000_000_000) {
                $error='Invalid budget draft. Check all fields and the date range.';$status=422;
            } else {
                try {
                    // Verify the actual source within this tenant before writing Finance.
                    $source=$this->read->query($context,'marketing.campaign.get',['id'=>$form['source_ref']]);
                    if (($source['campaign']['id']??null)!==$form['source_ref']) throw new DomainException('Campaign unavailable.');
                } catch (\Throwable) {
                    $error='Marketing campaign is unavailable or unauthorized.';$status=422;
                }
                if ($error===null) {
                    $trace=CorrelationId::generate()->value();
                    try {
                        $input=$form;
                        $input['source_domain']='marketing';
                        $input['limit_minor']=$limit;
                        $result=$this->gateway->invoke($actor,$action->command,$input,'human_ui',$key,$trace);
                        $id=$result['id']??null;
                        if (!is_string($id)||!preg_match('/^finb-[a-f0-9]{24}$/D',$id)) throw new DomainException('Draft receipt unavailable.');
                        $data=$this->read->query($context,'finance.budget.envelope.get',['id'=>$id]);
                        $after=$data['envelope']??null;
                        if (!is_array($after)||($after['id']??null)!==$id||($after['state']??null)!=='DRAFT'
                            ||($after['revision']??null)!==($result['revision']??null)||($after['revision']??null)!==1
                            ||($after['reserved_minor']??null)!==0||($after['committed_minor']??null)!==0) {
                            throw new DomainException('Draft readback unavailable.');
                        }
                        foreach ($input as $field=>$value) {
                            if (($after[$field]??null)!==$value) throw new DomainException('Draft input readback mismatch.');
                        }
                        $receipt=['id'=>$id,'revision'=>1,'trace'=>$trace,'path'=>WorkspaceCapabilityRead::workspacePath('finance.budget',new EntityRef('finance.budget',$id))];
                    } catch (\Throwable $e) {
                        error_log('cos.cuw.finance.draft_unverified '.$e::class.' trace='.$trace);
                        $error='Draft outcome is unverified. Inspect the budget list before retrying.';$status=503;
                    }
                }
            }
        }
        return new Response($this->twig->render('experience/unified/business/finance_budget_draft.html.twig',[
            'shell'=>$shell,'back'=>$back,'form'=>$form,'csrfToken'=>$this->csrf->token($request),
            'idempotencyKey'=>$key,'error'=>$error,'receipt'=>$receipt,'retryAllowed'=>$status!==503,
        ]),$status,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow']);
    }
}
