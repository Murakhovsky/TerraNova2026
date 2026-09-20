<?php
declare(strict_types=1);

namespace App\Web\Diagnostic;

use App\Security\LegacySessionReader;
use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class DiagnosticPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private LegacySessionReader $sessions,
        private NavigationBuilder $navigation,
        private DiagnosticRuntimeService $runtime,
        private DiagnosticMethodologyAccess $access,
    ) {}

    public function report(Request $request,string $session):Response
    {
        $tenant=$this->authenticated();
        if($tenant instanceof Response) return $tenant;

        try {
            $envelope=$this->runtime->report($tenant->organizationId()->value(),$session);
        } catch(Throwable) {
            return new Response('Diagnostic report was not found.',404,['Content-Type'=>'text/plain; charset=UTF-8']);
        }

        return new Response($this->renderer->render($request,'diagnostic_report/show',array_replace(
            $this->workspaceVariables($tenant,'Diagnostic Report','diagnostics'),
            [
                'sessionId'=>$session,
                'reportEnvelope'=>$envelope,
                'report'=>is_array($envelope['report'] ?? null) ? $envelope['report'] : [],
            ],
        )),200,['Content-Type'=>'text/html; charset=UTF-8']);
    }

    public function methodologyStudio(Request $request):Response
    {
        $tenant=$this->authenticated();
        if($tenant instanceof Response) return $tenant;

        $organizationId=$tenant->organizationId()->value();
        $userId=(int)$tenant->userId()->value();
        if(!$this->access->allows($organizationId,$userId,DiagnosticMethodologyAccess::VIEW)){
            return new Response('Forbidden',403);
        }

        return new Response($this->renderer->render($request,'methodology_studio/index',array_replace(
            $this->workspaceVariables($tenant,'Diagnostic Methodology Studio','diagnostics',['diagnostics-methodology-studio']),
            ['csrfToken'=>$this->csrf($request)],
        )),200,['Content-Type'=>'text/html; charset=UTF-8']);
    }

    private function authenticated():TenantContext|Response
    {
        $tenant=$this->tenants->current();
        return $tenant ?? new RedirectResponse('/auth/login');
    }

    /** @return array<string,mixed> */
    private function workspaceVariables(TenantContext $tenant,string $title,string $active,array $assets=[]):array
    {
        $role=$tenant->role()->value();
        return [
            'title'=>$title,
            'metaTitle'=>$title . ' | COS',
            'metaRobots'=>'noindex,nofollow',
            'workspaceSection'=>'cos',
            'workspaceActive'=>$active,
            'workspaceActiveSection'=>$this->navigation->activeSection($active),
            'pageAssetEntries'=>$assets,
            'currentUser'=>['id'=>(int)$tenant->userId()->value(),'role'=>$role],
            'role'=>$role,
            'isTeam'=>$tenant->isManager(),
            'isAdmin'=>$tenant->isAdmin(),
            'workspaceNavigation'=>$tenant->isManager() ? $this->navigation->workspace($tenant) : ['primary'=>[],'utility'=>[]],
        ];
    }

    private function csrf(Request $request):string
    {
        $sessionId=(string)$request->cookies->get($this->sessions->cookieName(),'');
        return $this->sessions->csrfToken($sessionId) ?? '';
    }
}
