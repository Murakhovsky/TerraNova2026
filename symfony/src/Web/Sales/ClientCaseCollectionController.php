<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Query\GetClientCaseCollectionQuery;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class ClientCaseCollectionController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private ClientCaseCollectionPresenter $presenter,
        private SessionCsrfValidator $csrf,
    ) {}

    public function index(Request $request): Response
    {
        $tenant=$this->manager(); if($tenant instanceof Response)return $tenant;
        $context=new WebExtensionContext(
            organizationId:$tenant->organizationId()->value(),
            role:$tenant->role()->value(),surface:'workspace',activeSection:'clients',activeItem:'cases',
        );
        $shell=$this->shells->create($tenant,$context,'Клієнтські кейси',[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Clients','/client-case/inbox'),
            new ShellBreadcrumb('Cases'),
        ]);

        try {
            $data=$this->queries->ask(new GetClientCaseCollectionQuery($tenant->organizationId(),$request->query->all()));
            $collection=$this->presenter->present(is_array($data)?$data:[]);
            return $this->render([
                'shell'=>$shell,
                'page'=>$this->pages->create(PageArchetype::Collection,$this->patterns(),$collection->state()),
                'collection'=>$collection,
                'csrfToken'=>$this->csrf->token($request),
                'actionStatus'=>trim((string)$request->query->get('status_message','')),
            ]);
        } catch(Throwable $error) {
            error_log('client-case.collection.read_failed '.$error->getMessage());
            $collection=$this->presenter->present([],'CRM кейсів тимчасово недоступна.');
            return $this->render([
                'shell'=>$shell,
                'page'=>$this->pages->create(PageArchetype::Collection,$this->patterns(),'error'),
                'collection'=>$collection,'csrfToken'=>$this->csrf->token($request),'actionStatus'=>'',
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
        return ['PageHeader','EntityList','FilterBar','ActionBar','EmptyState','ErrorState'];
    }

    private function render(array $variables,int $status=Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/client_case/index.html.twig',$variables),
            $status,
            ['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow'],
        );
    }
}
