<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Query\GetPropertySubmissionsQueueQuery;
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

final readonly class PropertySubmissionsController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private PropertySubmissionsPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant=$this->manager();
        if($tenant instanceof Response)return $tenant;

        $status=trim((string)$request->query->get('status',''));
        $page=max(1,(int)$request->query->get('page',1));
        $perPage=max(10,min(100,(int)$request->query->get('per_page',25)));

        $context=new WebExtensionContext(
            organizationId:$tenant->organizationId()->value(),
            role:$tenant->role()->value(),
            surface:'workspace',
            activeSection:'properties',
            activeItem:'submissions',
        );
        $shell=$this->shells->create($tenant,$context,'Property Moderation',[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Properties','/property/manage'),
            new ShellBreadcrumb('Moderation'),
        ]);

        try{
            $data=$this->queries->ask(new GetPropertySubmissionsQueueQuery(
                $tenant->organizationId(),$status,$page,$perPage,
            ));
            $queue=$this->presenter->present(is_array($data)?$data:[],$status,$page,$perPage);

            return $this->render([
                'shell'=>$shell,
                'page'=>$this->pages->create(PageArchetype::OperationalQueue,$this->patterns(),$queue->state()),
                'queue'=>$queue,
            ]);
        }catch(Throwable $error){
            error_log('property.submissions.read_failed '.$error->getMessage());
            $queue=$this->presenter->present([],$status,$page,$perPage,'Property submissions тимчасово недоступні.');
            return $this->render([
                'shell'=>$shell,
                'page'=>$this->pages->create(PageArchetype::OperationalQueue,$this->patterns(),'error'),
                'queue'=>$queue,
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

    /** @return list<string> */
    private function patterns(): array
    {
        return ['PageHeader','EntityList','KpiStrip','FilterBar','Pagination','EmptyState','ErrorState'];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables,int $status=Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/property/submissions.html.twig',$variables),
            $status,
            ['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow'],
        );
    }
}
