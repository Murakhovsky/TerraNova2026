<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Query\GetClientCaseWorkspaceQuery;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use App\Web\Experience\Workspace\WorkspaceCompositionResolver;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Twig\Environment;

final readonly class ClientCaseWorkspaceController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private WorkspaceCompositionResolver $workspaces,
        private PagePresentationFactory $pages,
        private ClientCaseWorkspacePresenter $presenter,
        private SessionCsrfValidator $csrf,
    ) {}

    public function index(Request $request,string $id): Response
    {
        $tenant=$this->manager(); if($tenant instanceof Response)return $tenant;
        $caseId=(int)$id;
        if($caseId<=0)throw new NotFoundHttpException('Client case not found.');

        $context=new WebExtensionContext(
            organizationId:$tenant->organizationId()->value(),
            role:$tenant->role()->value(),
            surface:'workspace',activeSection:'clients',activeItem:'cases',
        );
        $shell=$this->shells->create($tenant,$context,'Client Case Workspace',[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Clients','/client-case/inbox'),
            new ShellBreadcrumb('Cases','/client-case'),
            new ShellBreadcrumb('Case #'.$caseId),
        ]);
        $workspace=$this->workspaces->resolve(
            $tenant,$context,'sales.client_case',new EntityRef('sales.deal',(string)$caseId),
        );

        try {
            $data=$this->queries->ask(new GetClientCaseWorkspaceQuery($tenant->organizationId(),$caseId));
            if(!is_array($data))throw new NotFoundHttpException('Client case not found.');
            $case=$this->presenter->present($data,$caseId);

            return $this->render([
                'shell'=>$shell,'workspace'=>$workspace,
                'page'=>$this->pages->create(PageArchetype::EntityWorkspace,$this->patterns(),$case->state()),
                'case'=>$case,'csrfToken'=>$this->csrf->token($request),
                'actionStatus'=>trim((string)$request->query->get('status_message','')),
            ]);
        } catch(NotFoundHttpException $error) {
            throw $error;
        } catch(Throwable $error) {
            error_log('client-case.workspace.read_failed '.$error->getMessage());
            $case=$this->presenter->present([],$caseId,'Client Case Workspace тимчасово недоступний.');
            return $this->render([
                'shell'=>$shell,'workspace'=>$workspace,
                'page'=>$this->pages->create(PageArchetype::EntityWorkspace,$this->patterns(),'error'),
                'case'=>$case,'csrfToken'=>$this->csrf->token($request),'actionStatus'=>'',
            ],Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return new RedirectResponse('/auth/login');
        if(!$tenant->isManager())return new Response('Forbidden',Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function patterns(): array
    {
        return ['WorkspaceHeader','EntityHeader','KpiStrip','ContextPanel','ActionBar','Timeline','EmptyState','ErrorState'];
    }

    private function render(array $variables,int $status=Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/client_case/show.html.twig',$variables),
            $status,
            ['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow'],
        );
    }
}
