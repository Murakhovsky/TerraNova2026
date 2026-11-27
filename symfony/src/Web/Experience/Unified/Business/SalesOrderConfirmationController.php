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
use DomainException;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Twig\Environment;

#[AsController]
final readonly class SalesOrderConfirmationController
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

    public function confirm(Request $request):Response
    {
        $actor=$this->tenants->current();
        if ($actor===null) return new RedirectResponse('/auth/login');
        if (!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)||!$this->csrf->isValid($request)) {
            return new Response('Forbidden',403);
        }
        $id=(string)$request->request->get('order_id','');
        $revision=filter_var($request->request->get('expected_revision'),FILTER_VALIDATE_INT);
        $key=(string)$request->request->get('idempotency_key','');
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)||$revision===false||$revision<1
            ||!preg_match('/^cuw-[a-f0-9]{32}$/D',$key)||$request->request->get('confirm')!=='yes') {
            return new Response('Invalid confirmation.',422);
        }

        $context=new WebExtensionContext($actor->organizationId()->value(),$actor->role()->value(),'workspace','workspaces','unified');
        $entity=new EntityRef('sales.order',$id);
        $trace=CorrelationId::generate()->value();
        try {
            // Exact owner/tenant-scoped read, followed by the canonical UIAction
            // permission resolver and live Domain authorization inside the gateway.
            $before=$this->read->query($context,'sales.order.read',['order_id'=>$id]);
            if (($before['order']['order_id']??null)!==$id) throw new DomainException('Order unavailable.');
            $action=$this->actions->resolveOne('sales.order.confirm',$actor,$context,$entity,UIActionPlacement::WORKSPACE_PRIMARY);
            if (!$action->enabled||$action->command!=='sales.order.lifecycle.confirm') return new Response('Forbidden',403);
        } catch (\Throwable) {
            return new Response('Order or command unavailable.',404);
        }
        $receipt=null;
        $outcome='unknown';
        try {
            // The stable submission key binds the document to a specific operation.
            // Native receipts handle replay and expected_revision fences stale forms.
            $result=$this->gateway->invoke($actor,$action->command,[
                'order_id'=>$id,'expected_revision'=>$revision,'reference'=>$key,
            ],'human_ui',$key,$trace);
            $after=$this->read->query($context,'sales.order.lifecycle.read',['order_id'=>$id]);
            if (!is_array($result)||($result['order_id']??null)!==$id||($after['order_id']??null)!==$id
                ||($result['status']??null)!=='confirmed'||($after['status']??null)!=='confirmed'
                ||!is_int($after['revision']??null)||$after['revision']<=$revision
                ||($result['revision']??null)!==$after['revision']) {
                throw new DomainException('Confirmation readback not verified.');
            }
            $receipt=['status'=>$after['status'],'revision'=>$after['revision'],'trace'=>$trace];
            $outcome='verified';
        } catch (\Throwable $e) {
            // After a dispatch attempt a failure is not proof of no mutation.
            error_log('cos.cuw.sales.confirm_unverified '.$e::class.' trace='.$trace);
        }
        $shell=$this->shells->create($actor,$context,'Order confirmation',[
            new ShellBreadcrumb('Workspaces','/workspace/unified'),
            new ShellBreadcrumb('Orders',WorkspaceCapabilityRead::workspacePath('sales.order')),
            new ShellBreadcrumb('Order confirmation'),
        ],aiAvailable:false,connectionState:ShellConnectionState::Stale);
        return new Response($this->twig->render('experience/unified/business/sales_order_confirmation.html.twig',[
            'shell'=>$shell,'orderId'=>$id,'receipt'=>$receipt,'outcome'=>$outcome,
            'back'=>WorkspaceCapabilityRead::workspacePath('sales.order',$entity),
        ]),$outcome==='verified'?200:503,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow']);
    }
}
